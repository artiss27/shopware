<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductMergeService
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * Generate preview data for product merge
     */
    public function generatePreview(
        string $mode,
        array $selectedProductIds,
        ?string $targetParentId = null,
        ?string $newParentName = null,
        array $variantFormingPropertyGroupIds = [],
        Context $context
    ): array {
        // Load all selected products with associations
        $products = $this->loadProductsWithAssociations($selectedProductIds, $context);

        if (empty($products)) {
            throw new \InvalidArgumentException('No products found');
        }

        if ($mode === 'new' && count($products) < 2) {
            throw new \InvalidArgumentException('At least 2 products required for new parent mode');
        }

        if ($mode === 'existing' && empty($targetParentId)) {
            throw new \InvalidArgumentException('Target parent ID required for existing parent mode');
        }

        $targetParent = null;
        if ($mode === 'existing') {
            $targetProducts = $this->loadProductsWithAssociations([$targetParentId], $context);
            $targetParent = $targetProducts[$targetParentId] ?? null;
            if (!$targetParent) {
                throw new \InvalidArgumentException('Target parent product not found');
            }
        } else {
            // Use first product as base for new parent
            $targetParent = reset($products);
        }

        // Analyze common and unique properties
        $propertyAnalysis = $this->analyzeProperties($products, $targetParent, $mode, $context);

        // Analyze custom fields
        $customFieldsAnalysis = $this->analyzeCustomFields($products);

        // Analyze media
        $mediaAnalysis = $this->analyzeMedia($products, $targetParent, $mode);

        // Generate variant-forming properties
        $allVariantFormingProperties = $this->getVariantFormingPropertiesFromAnalysis($propertyAnalysis, $products, $targetParent, $mode, $context);
        
        // Filter by selected property group IDs if provided
        $variantFormingProperties = $allVariantFormingProperties;
        if (!empty($variantFormingPropertyGroupIds)) {
            $variantFormingProperties = array_filter($allVariantFormingProperties, function ($prop) use ($variantFormingPropertyGroupIds) {
                return in_array($prop['groupId'], $variantFormingPropertyGroupIds);
            });
        }

        return [
            'parent' => [
                'id' => $targetParent->getId(),
                'name' => $mode === 'new' ? ($newParentName ?: $targetParent->getName()) : $targetParent->getName(),
                'productNumber' => $mode === 'existing' ? $targetParent->getProductNumber() : null
            ],
            'variants' => array_map(function (ProductEntity $product) {
                return [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'productNumber' => $product->getProductNumber()
                ];
            }, $products),
            'variantFormingProperties' => array_values($variantFormingProperties),
            'propertyAnalysis' => $propertyAnalysis,
            'customFieldsAnalysis' => $customFieldsAnalysis
        ];
    }

    /**
     * Get variant-forming properties list for selection
     */
    public function getVariantFormingProperties(
        string $mode,
        array $selectedProductIds,
        ?string $targetParentId = null,
        Context $context
    ): array {
        // Load all selected products with associations
        $products = $this->loadProductsWithAssociations($selectedProductIds, $context);

        if (empty($products)) {
            return [];
        }

        $targetParent = null;
        if ($mode === 'existing' && $targetParentId) {
            $targetProducts = $this->loadProductsWithAssociations([$targetParentId], $context);
            $targetParent = $targetProducts[$targetParentId] ?? null;
        } else {
            $targetParent = reset($products);
        }

        // Analyze properties
        $propertyAnalysis = $this->analyzeProperties($products, $targetParent, $mode, $context);

        // Generate variant-forming properties
        return $this->getVariantFormingPropertiesFromAnalysis($propertyAnalysis, $products, $targetParent, $mode, $context);
    }

    /**
     * Execute product merge
     */
    public function merge(
        string $mode,
        array $selectedProductIds,
        ?string $targetParentId = null,
        ?string $newParentName = null,
        array $variantFormingPropertyGroupIds = [],
        bool $mergeAllMedia = true,
        Context $context
    ): array {
        $this->connection->beginTransaction();

        try {
            // Load all selected products with associations
            $products = $this->loadProductsWithAssociations($selectedProductIds, $context);

            if (empty($products)) {
                throw new \InvalidArgumentException('No products found');
            }

            $targetParent = null;
            $newParentId = null;

            if ($mode === 'existing') {
                if (empty($targetParentId)) {
                    throw new \InvalidArgumentException('Target parent ID required for existing parent mode');
                }
                $targetProducts = $this->loadProductsWithAssociations([$targetParentId], $context);
                $targetParent = $targetProducts[$targetParentId] ?? null;
                if (!$targetParent) {
                    throw new \InvalidArgumentException('Target parent product not found');
                }
                $newParentId = $targetParentId;
            } else {
                // Clone first product to create new parent
                $firstProduct = reset($products);
                $newParentId = $this->cloneProductForParent($firstProduct, $newParentName ?: $firstProduct->getName(), $context);
                
                // Load the newly created parent
                $targetProducts = $this->loadProductsWithAssociations([$newParentId], $context);
                $targetParent = $targetProducts[$newParentId] ?? null;
                
                if (!$targetParent) {
                    throw new \RuntimeException('Failed to create new parent product');
                }

                // Process properties, custom fields, and media for new parent
                $this->processNewParentData($targetParent, $products, $variantFormingPropertyGroupIds, $context);
            }

            // Process media BEFORE setting parentId (copy media to parent first)
            $this->processMedia($targetParent, $products, $mergeAllMedia, $context);

            // Process configurator settings to create configurator options on parent
            $this->processConfiguratorSettings($targetParent, $products, $variantFormingPropertyGroupIds, $context);

            // Set parent_id and variant options for all selected products
            $updates = [];
            $firstVariantId = null;
            foreach ($products as $product) {
                if ($firstVariantId === null) {
                    $firstVariantId = $product->getId();
                }

                // Get variant-forming options for this product (only these should remain)
                $variantOptions = $this->getVariantOptions($product, $variantFormingPropertyGroupIds);

                $updateData = [
                    'id' => $product->getId(),
                    'parentId' => $newParentId
                ];

                // Set options that define this variant
                if (!empty($variantOptions)) {
                    $updateData['options'] = $variantOptions;
                }

                // Clear fields that should be inherited from parent (set to null)
                $updateData['manufacturerId'] = null;
                $updateData['taxId'] = null;
                $updateData['minPurchase'] = null;
                $updateData['purchaseSteps'] = null;
                $updateData['description'] = null;
                $updateData['active'] = null;

                $updates[] = $updateData;
            }

            $this->productRepository->update($updates, $context);

            // Reload products after setting parentId to get fresh data
            $productIds = array_map(fn($p) => $p->getId(), $products);
            $products = array_values($this->loadProductsWithAssociations($productIds, $context));

            // Clear categories, visibilities, and common properties from variants
            $this->clearVariantAssociations($products, $variantFormingPropertyGroupIds, $context);

            // Set main variant and variant listing config on parent
            if ($firstVariantId) {
                $this->productRepository->update([
                    [
                        'id' => $newParentId,
                        'mainVariantId' => $firstVariantId,
                        'variantListingConfig' => [
                            'displayParent' => true,
                            'mainVariantId' => $firstVariantId,
                            'configuratorGroupConfig' => null
                        ]
                    ]
                ], $context);
            }

            $this->connection->commit();

            return [
                'success' => true,
                'parentId' => $newParentId,
                'variantsCount' => count($products)
            ];
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Load products with all necessary associations
     */
    private function loadProductsWithAssociations(array $productIds, Context $context): array
    {
        $criteria = new Criteria($productIds);
        $criteria->addAssociations([
            'properties',
            'properties.group',
            'properties.group.options',
            'categories',
            'media',
            'cover',
            'configuratorSettings',
            'configuratorSettings.option',
            'configuratorSettings.option.group',
            'customFields',
            'prices',
            'tax',
            'manufacturer',
            'visibilities'
        ]);

        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $result = [];
        foreach ($products as $product) {
            $result[$product->getId()] = $product;
        }

        return $result;
    }

    /**
     * Analyze properties across products
     */
    private function analyzeProperties(array $products, ?ProductEntity $targetParent, string $mode, Context $context): array
    {
        $allProperties = [];
        foreach ($products as $product) {
            $properties = $product->getProperties();
            if ($properties) {
                foreach ($properties as $property) {
                    $groupId = $property->getGroupId();
                    $optionId = $property->getId();
                    
                    if (!isset($allProperties[$groupId])) {
                        $allProperties[$groupId] = [];
                    }
                    if (!isset($allProperties[$groupId][$optionId])) {
                        $allProperties[$groupId][$optionId] = [];
                    }
                    $allProperties[$groupId][$optionId][] = $product->getId();
                }
            }
        }

        // Add existing parent properties if mode is 'existing'
        if ($mode === 'existing' && $targetParent) {
            $parentProducts = $this->loadProductsWithAssociations(
                [$targetParent->getId()],
                $context
            );
            $parentProduct = $parentProducts[$targetParent->getId()] ?? null;
            
            if ($parentProduct) {
                $existingChildren = $this->getProductChildren($targetParent->getId(), $context);
                foreach ($existingChildren as $child) {
                    $properties = $child->getProperties();
                    if ($properties) {
                        foreach ($properties as $property) {
                            $groupId = $property->getGroupId();
                            $optionId = $property->getId();
                            
                            if (!isset($allProperties[$groupId])) {
                                $allProperties[$groupId] = [];
                            }
                            if (!isset($allProperties[$groupId][$optionId])) {
                                $allProperties[$groupId][$optionId] = [];
                            }
                            // Mark as existing child
                            $allProperties[$groupId][$optionId][] = $child->getId();
                        }
                    }
                }
            }
        }

        // Find common properties (present in all products)
        $commonProperties = [];
        $uniqueProperties = [];
        $totalProducts = count($products);

        foreach ($allProperties as $groupId => $options) {
            foreach ($options as $optionId => $productIds) {
                $uniqueProductIds = array_unique($productIds);
                if (count($uniqueProductIds) === $totalProducts) {
                    if (!isset($commonProperties[$groupId])) {
                        $commonProperties[$groupId] = [];
                    }
                    $commonProperties[$groupId][] = $optionId;
                } else {
                    if (!isset($uniqueProperties[$groupId])) {
                        $uniqueProperties[$groupId] = [];
                    }
                    $uniqueProperties[$groupId][] = $optionId;
                }
            }
        }

        return [
            'common' => $commonProperties,
            'unique' => $uniqueProperties,
            'all' => $allProperties
        ];
    }

    /**
     * Analyze custom fields across products
     */
    private function analyzeCustomFields(array $products): array
    {
        $allCustomFields = [];
        foreach ($products as $product) {
            $customFields = $product->getCustomFields();
            if ($customFields) {
                foreach ($customFields as $key => $value) {
                    if (!isset($allCustomFields[$key])) {
                        $allCustomFields[$key] = [];
                    }
                    $allCustomFields[$key][] = [
                        'productId' => $product->getId(),
                        'value' => $value
                    ];
                }
            }
        }

        $commonFields = [];
        $uniqueFields = [];
        $totalProducts = count($products);

        foreach ($allCustomFields as $key => $values) {
            $uniqueValues = array_unique(array_column($values, 'value'), SORT_REGULAR);
            // Only move to parent if: 1) from product_custom_properties set, 2) same value across all products
            $isFromProductCustomProperties = str_starts_with($key, 'product_custom_properties_');

            if ($isFromProductCustomProperties && count($uniqueValues) === 1 && count($values) === $totalProducts) {
                $commonFields[$key] = $uniqueValues[0];
            } else {
                $uniqueFields[$key] = $values;
            }
        }

        return [
            'common' => $commonFields,
            'unique' => $uniqueFields
        ];
    }

    /**
     * Analyze media across products
     */
    private function analyzeMedia(array $products, ?ProductEntity $targetParent, string $mode): array
    {
        $allMedia = [];
        foreach ($products as $product) {
            $mediaCollection = $product->getMedia();
            if ($mediaCollection) {
                foreach ($mediaCollection as $media) {
                    $mediaId = $media->getMediaId();
                    if (!isset($allMedia[$mediaId])) {
                        $allMedia[$mediaId] = [];
                    }
                    $allMedia[$mediaId][] = $product->getId();
                }
            }
        }

        // Add existing parent media if mode is 'existing'
        if ($mode === 'existing' && $targetParent) {
            $mediaCollection = $targetParent->getMedia();
            if ($mediaCollection) {
                foreach ($mediaCollection as $media) {
                    $mediaId = $media->getMediaId();
                    if (!isset($allMedia[$mediaId])) {
                        $allMedia[$mediaId] = [];
                    }
                }
            }
        }

        $totalProducts = count($products);
        $commonMedia = [];
        $uniqueMedia = [];

        foreach ($allMedia as $mediaId => $productIds) {
            $uniqueProductIds = array_unique($productIds);
            if (count($uniqueProductIds) === $totalProducts) {
                $commonMedia[] = $mediaId;
            } else {
                $uniqueMedia[] = $mediaId;
            }
        }

        return [
            'common' => count($commonMedia),
            'unique' => count($uniqueMedia)
        ];
    }

    /**
     * Get variant-forming properties from analysis
     */
    private function getVariantFormingPropertiesFromAnalysis(
        array $propertyAnalysis,
        array $products,
        ?ProductEntity $targetParent,
        string $mode,
        Context $context
    ): array {
        $variantForming = [];

        // Find property groups where options differ between products
        foreach ($propertyAnalysis['all'] as $groupId => $options) {
            $optionIds = array_keys($options);
            if (count($optionIds) > 1) {
                // Get group and options info
                $group = $this->getPropertyGroup($groupId, $context);
                if ($group) {
                    $groupOptions = [];
                    foreach ($optionIds as $optionId) {
                        $option = $this->getPropertyOption($optionId, $context);
                        if ($option) {
                            $groupOptions[] = [
                                'id' => $optionId,
                                'name' => $option->getTranslation('name') ?? $option->getName()
                            ];
                        }
                    }
                    
                    $variantForming[] = [
                        'groupId' => $groupId,
                        'groupName' => $group->getTranslation('name') ?? $group->getName(),
                        'options' => $groupOptions
                    ];
                }
            }
        }

        return $variantForming;
    }

    /**
     * Clone product to create new parent
     */
    private function cloneProductForParent(ProductEntity $sourceProduct, string $newName, Context $context): string
    {
        $newId = Uuid::randomHex();

        // Generate parent product number with P- prefix
        $newProductNumber = 'P-' . $sourceProduct->getProductNumber();

        $productData = [
            'id' => $newId,
            'name' => $newName,
            'productNumber' => $newProductNumber,
            'parentId' => null,
            'taxId' => $sourceProduct->getTaxId(),
            'stock' => 0,
            'active' => $sourceProduct->getActive(),
            'markAsTopseller' => $sourceProduct->getMarkAsTopseller(),
        ];

        // Copy price - required field
        if ($sourceProduct->getPrice()) {
            $productData['price'] = $sourceProduct->getPrice();
        }

        // Copy minPurchase and purchaseSteps
        if ($sourceProduct->getMinPurchase()) {
            $productData['minPurchase'] = $sourceProduct->getMinPurchase();
        }
        if ($sourceProduct->getPurchaseSteps()) {
            $productData['purchaseSteps'] = $sourceProduct->getPurchaseSteps();
        }

        // Copy categories
        if ($sourceProduct->getCategories()) {
            $productData['categories'] = array_map(function ($category) {
                return ['id' => $category->getId()];
            }, $sourceProduct->getCategories()->getElements());
        }

        // Copy manufacturer
        if ($sourceProduct->getManufacturerId()) {
            $productData['manufacturerId'] = $sourceProduct->getManufacturerId();
        }

        // Copy description
        if ($sourceProduct->getDescription()) {
            $productData['description'] = $sourceProduct->getDescription();
        }

        // Copy dimensions and weight
        if ($sourceProduct->getWidth()) {
            $productData['width'] = $sourceProduct->getWidth();
        }
        if ($sourceProduct->getHeight()) {
            $productData['height'] = $sourceProduct->getHeight();
        }
        if ($sourceProduct->getLength()) {
            $productData['length'] = $sourceProduct->getLength();
        }
        if ($sourceProduct->getWeight()) {
            $productData['weight'] = $sourceProduct->getWeight();
        }

        // Copy sales channel visibilities
        $productData['visibilities'] = $this->copySalesChannelVisibilities($sourceProduct);

        // Copy properties
        if ($sourceProduct->getProperties()) {
            $productData['properties'] = array_map(function ($property) {
                return ['id' => $property->getId()];
            }, $sourceProduct->getProperties()->getElements());
        }

        // Copy custom fields
        if ($sourceProduct->getCustomFields()) {
            $productData['customFields'] = $sourceProduct->getCustomFields();
        }

        $this->productRepository->create([$productData], $context);

        return $newId;
    }

    /**
     * Copy sales channel visibilities from source product
     */
    private function copySalesChannelVisibilities(ProductEntity $sourceProduct): array
    {
        $visibilities = [];

        if ($sourceProduct->getVisibilities()) {
            foreach ($sourceProduct->getVisibilities() as $visibility) {
                $visibilities[] = [
                    'salesChannelId' => $visibility->getSalesChannelId(),
                    'visibility' => $visibility->getVisibility()
                ];
            }
        }

        return $visibilities;
    }

    /**
     * Process data for new parent (remove non-common properties, custom fields, etc.)
     */
    private function processNewParentData(ProductEntity $parent, array $products, array $variantFormingPropertyGroupIds, Context $context): void
    {
        // Parent is already a copy of donor (first product) with all its data
        // Now we need to remove properties and custom fields that differ between variants

        $this->simplifyParentData($parent, $products, $variantFormingPropertyGroupIds, $context);
    }

    /**
     * Simplify parent data by removing properties/custom fields that differ between variants
     */
    private function simplifyParentData(ProductEntity $parent, array $products, array $variantFormingPropertyGroupIds, Context $context): void
    {
        // Get custom field set name from config
        $customFieldSetName = $this->systemConfigService->get('ArtissTools.config.productMergeCustomFieldSet') ?? 'product_custom_properties';

        // Get list of custom field technical names from this set
        $customFieldNames = $this->getCustomFieldNamesFromSet($customFieldSetName, $context);

        // 1. Get all properties from parent
        $parentProperties = [];
        if ($parent->getProperties()) {
            foreach ($parent->getProperties() as $property) {
                $parentProperties[$property->getId()] = [
                    'groupId' => $property->getGroupId(),
                    'optionId' => $property->getId()
                ];
            }
        }

        // 2. Check which properties differ between variants
        $propertiesToRemoveFromParent = [];
        foreach ($parentProperties as $propertyId => $propertyData) {
            $groupId = $propertyData['groupId'];

            // Skip variant-forming properties - they should not be on parent anyway
            if (in_array($groupId, $variantFormingPropertyGroupIds)) {
                $propertiesToRemoveFromParent[] = $propertyId;
                continue;
            }

            // Check if ALL variants have this exact property value
            foreach ($products as $productIndex => $product) {
                $hasThisProperty = false;
                if ($product->getProperties()) {
                    foreach ($product->getProperties() as $variantProperty) {
                        if ($variantProperty->getId() === $propertyId) {
                            $hasThisProperty = true;
                            break;
                        }
                    }
                }

                // If at least one variant doesn't have this property → remove from parent
                if (!$hasThisProperty) {
                    $propertiesToRemoveFromParent[] = $propertyId;
                    break;
                }
            }
        }

        // 3. Remove different properties from parent
        foreach ($propertiesToRemoveFromParent as $propertyId) {
            $this->connection->executeStatement(
                'DELETE FROM product_property WHERE product_id = :productId AND property_group_option_id = :propertyId',
                [
                    'productId' => Uuid::fromHexToBytes($parent->getId()),
                    'propertyId' => Uuid::fromHexToBytes($propertyId)
                ]
            );
        }

        // 4. Get all custom fields from parent with the configured prefix
        $parentCustomFields = $parent->getCustomFields() ?? [];
        $customFieldsToRemoveFromParent = [];

        foreach ($parentCustomFields as $key => $value) {
            // Only process fields from configured set
            if (!in_array($key, $customFieldNames)) {
                continue;
            }

            // Check if ALL variants have this exact custom field value
            foreach ($products as $productIndex => $product) {
                $variantCustomFields = $product->getCustomFields() ?? [];

                // If variant doesn't have this field OR has different value → remove from parent
                if (!isset($variantCustomFields[$key]) || $variantCustomFields[$key] !== $value) {
                    $customFieldsToRemoveFromParent[] = $key;
                    break;
                }
            }
        }

        // 5. Remove different custom fields from parent using direct SQL
        if (!empty($customFieldsToRemoveFromParent)) {
            $updatedParentCustomFields = $parentCustomFields;
            foreach ($customFieldsToRemoveFromParent as $key) {
                unset($updatedParentCustomFields[$key]);
            }

            // Update all language translations for this product
            $this->connection->executeStatement(
                'UPDATE product_translation SET custom_fields = :customFields WHERE product_id = :productId',
                [
                    'customFields' => json_encode($updatedParentCustomFields),
                    'productId' => Uuid::fromHexToBytes($parent->getId())
                ]
            );
        }
    }

    /**
     * Process configurator settings for variant-forming properties
     */
    private function processConfiguratorSettings(ProductEntity $parent, array $products, array $selectedPropertyGroupIds, Context $context): void
    {
        if (empty($selectedPropertyGroupIds)) {
            return;
        }

        $propertyAnalysis = $this->analyzeProperties($products, $parent, 'existing', $context);
        $allVariantFormingProperties = $this->getVariantFormingPropertiesFromAnalysis($propertyAnalysis, $products, $parent, 'existing', $context);
        
        // Filter by selected property group IDs
        $variantFormingProperties = array_filter($allVariantFormingProperties, function ($prop) use ($selectedPropertyGroupIds) {
            return in_array($prop['groupId'], $selectedPropertyGroupIds);
        });

        $configuratorSettings = [];
        foreach ($variantFormingProperties as $property) {
            foreach ($property['options'] as $option) {
                $configuratorSettings[] = [
                    'id' => Uuid::randomHex(),
                    'optionId' => $option['id']
                ];
            }
        }

        if (!empty($configuratorSettings)) {
            $this->productRepository->update([
                [
                    'id' => $parent->getId(),
                    'configuratorSettings' => $configuratorSettings
                ]
            ], $context);
        }
    }

    /**
     * Process media (copy first product's media to parent, remove duplicates from variants)
     */
    private function processMedia(ProductEntity $parent, array $products, bool $mergeAllMedia, Context $context): void
    {
        if (!$mergeAllMedia) {
            // Don't merge media, parent will have no media, variants keep their media
            return;
        }

        // Take media ONLY from first product (donor)
        $firstProduct = reset($products);
        if (!$firstProduct) {
            return;
        }

        $donorMediaIds = [];
        $donorCoverId = null;

        // Collect media from donor
        if ($firstProduct->getMedia()) {
            foreach ($firstProduct->getMedia() as $productMedia) {
                $donorMediaIds[] = $productMedia->getMediaId();
            }
        }

        // Get cover from donor
        if ($firstProduct->getCover()) {
            $donorCoverId = $firstProduct->getCover()->getMediaId();
        }

        if (empty($donorMediaIds)) {
            return;
        }

        // Copy donor's media to parent
        $mediaArray = [];
        foreach ($donorMediaIds as $index => $mediaId) {
            $mediaArray[] = [
                'mediaId' => $mediaId,
                'position' => $index
            ];
        }

        $parentUpdate = [
            'id' => $parent->getId(),
            'media' => $mediaArray
        ];

        // Set cover from donor
        if ($donorCoverId) {
            // First, find the product_media_id for this media on the parent
            // We need to update after media is added to get the correct product_media_id
            $parentUpdate['coverId'] = $donorCoverId;
        }

        $this->productRepository->update([$parentUpdate], $context);

        // Now set the cover - need to find the product_media.id for the cover media
        if ($donorCoverId) {
            $productMediaId = $this->connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM product_media WHERE product_id = :productId AND media_id = :mediaId LIMIT 1',
                [
                    'productId' => Uuid::fromHexToBytes($parent->getId()),
                    'mediaId' => Uuid::fromHexToBytes($donorCoverId)
                ]
            );

            if ($productMediaId) {
                $this->productRepository->update([[
                    'id' => $parent->getId(),
                    'coverId' => $productMediaId
                ]], $context);
            }
        }

        // Delete ALL media from ALL variants using direct SQL
        foreach ($products as $product) {
            $this->connection->executeStatement(
                'DELETE FROM product_media WHERE product_id = :productId',
                ['productId' => Uuid::fromHexToBytes($product->getId())]
            );
        }
    }

    /**
     * Clear categories, visibilities, and common properties from variants so they inherit from parent
     */
    private function clearVariantAssociations(array $products, array $variantFormingPropertyGroupIds, Context $context): void
    {
        // Get parent ID from first product
        if (empty($products)) {
            return;
        }

        $firstProduct = reset($products);
        $parentId = $firstProduct->getParentId();

        if (!$parentId) {
            return;
        }

        // Load parent with properties
        $parentProducts = $this->loadProductsWithAssociations([$parentId], $context);
        $parent = $parentProducts[$parentId] ?? null;

        if (!$parent) {
            return;
        }

        // Delete variant-forming properties from parent
        if ($parent->getProperties()) {
            foreach ($parent->getProperties() as $property) {
                if (in_array($property->getGroupId(), $variantFormingPropertyGroupIds)) {
                    $this->connection->executeStatement(
                        'DELETE FROM product_property WHERE product_id = :productId AND property_group_option_id = :propertyId',
                        [
                            'productId' => Uuid::fromHexToBytes($parent->getId()),
                            'propertyId' => Uuid::fromHexToBytes($property->getId())
                        ]
                    );
                }
            }
        }

        // Get custom field set name from config
        $customFieldSetName = $this->systemConfigService->get('ArtissTools.config.productMergeCustomFieldSet') ?? 'product_custom_properties';

        // Get list of custom field technical names from this set
        $customFieldNames = $this->getCustomFieldNamesFromSet($customFieldSetName, $context);

        // Collect parent properties (non-variant-forming)
        $parentPropertyIds = [];
        if ($parent->getProperties()) {
            foreach ($parent->getProperties() as $property) {
                $parentPropertyIds[] = $property->getId();
            }
        }

        // Collect parent custom fields from configured set
        $parentCustomFields = $parent->getCustomFields() ?? [];
        $parentCustomFieldKeys = [];
        foreach ($parentCustomFields as $key => $value) {
            if (in_array($key, $customFieldNames)) {
                $parentCustomFieldKeys[$key] = $value;
            }
        }

        foreach ($products as $product) {
            // Delete all category associations for this variant
            $this->connection->executeStatement(
                'DELETE FROM product_category WHERE product_id = :productId',
                ['productId' => Uuid::fromHexToBytes($product->getId())]
            );

            // Delete all visibility associations for this variant
            $this->connection->executeStatement(
                'DELETE FROM product_visibility WHERE product_id = :productId',
                ['productId' => Uuid::fromHexToBytes($product->getId())]
            );

            // Delete properties that exist in parent (including variant-forming since they were already removed from parent)
            if ($product->getProperties()) {
                foreach ($product->getProperties() as $property) {
                    // Delete if property exists in parent
                    if (in_array($property->getId(), $parentPropertyIds)) {
                        $this->connection->executeStatement(
                            'DELETE FROM product_property WHERE product_id = :productId AND property_group_option_id = :propertyId',
                            [
                                'productId' => Uuid::fromHexToBytes($product->getId()),
                                'propertyId' => Uuid::fromHexToBytes($property->getId())
                            ]
                        );
                    }
                }
            }

            // Delete custom fields from configured set that exist in parent with same value
            if (!empty($parentCustomFieldKeys)) {
                $productCustomFields = $product->getCustomFields() ?? [];
                $updatedCustomFields = $productCustomFields;
                $hasChanges = false;

                foreach ($parentCustomFieldKeys as $key => $parentValue) {
                    // If variant has this field with same value, remove it
                    if (isset($productCustomFields[$key]) && $productCustomFields[$key] === $parentValue) {
                        unset($updatedCustomFields[$key]);
                        $hasChanges = true;
                    }
                }

                // Update variant custom fields if changed using direct SQL
                if ($hasChanges) {
                    $this->connection->executeStatement(
                        'UPDATE product_translation SET custom_fields = :customFields WHERE product_id = :productId',
                        [
                            'customFields' => json_encode($updatedCustomFields),
                            'productId' => Uuid::fromHexToBytes($product->getId())
                        ]
                    );
                }
            }
        }
    }

    /**
     * Get property group
     */
    private function getPropertyGroup(string $groupId, Context $context): ?object
    {
        $criteria = new Criteria([$groupId]);
        $result = $this->propertyGroupRepository->search($criteria, $context);
        return $result->first();
    }

    /**
     * Get property option
     */
    private function getPropertyOption(string $optionId, Context $context): ?object
    {
        $criteria = new Criteria([$optionId]);
        $result = $this->propertyGroupOptionRepository->search($criteria, $context);
        return $result->first();
    }

    /**
     * Get variant options for a product based on selected variant-forming property groups
     */
    private function getVariantOptions(ProductEntity $product, array $variantFormingPropertyGroupIds): array
    {
        $options = [];
        $properties = $product->getProperties();

        if (!$properties) {
            return $options;
        }

        foreach ($properties as $property) {
            // Only include properties from variant-forming groups
            if (in_array($property->getGroupId(), $variantFormingPropertyGroupIds)) {
                $options[] = ['id' => $property->getId()];
            }
        }

        return $options;
    }

    /**
     * Get product children
     */
    private function getProductChildren(string $parentId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        $criteria->addAssociations(['properties', 'properties.group', 'properties.group.options']);

        $children = $this->productRepository->search($criteria, $context)->getEntities();
        return array_values($children->getElements());
    }

    /**
     * Get custom field technical names from a custom field set
     */
    private function getCustomFieldNamesFromSet(string $setName, Context $context): array
    {
        // Query custom_field_set table to get the set ID and custom fields
        $setId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name LIMIT 1',
            ['name' => $setName]
        );

        if (!$setId) {
            return [];
        }

        // Query custom_field table to get all fields in this set
        $fields = $this->connection->fetchAllAssociative(
            'SELECT name FROM custom_field WHERE set_id = :setId',
            ['setId' => Uuid::fromHexToBytes($setId)]
        );

        return array_column($fields, 'name');
    }
}

