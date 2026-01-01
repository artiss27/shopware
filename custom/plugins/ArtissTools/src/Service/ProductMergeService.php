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

class ProductMergeService
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $propertyGroupOptionRepository,
        private readonly Connection $connection
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

            // Process configurator settings first to create configurator options on parent
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

                // Set only variant-forming properties, clear all others
                $variantPropertyIds = array_column($variantOptions, 'id');
                if (!empty($variantPropertyIds)) {
                    $updateData['properties'] = $variantOptions;
                } else {
                    $updateData['properties'] = [];
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

            // Clear categories and visibilities from variants (must be done after parentId is set)
            $this->clearVariantAssociations($products, $context);

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

            // Process media
            $this->processMedia($targetParent, $products, $context);

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
        $propertyAnalysis = $this->analyzeProperties($products, $parent, 'new', $context);
        $customFieldsAnalysis = $this->analyzeCustomFields($products);

        // Set only common properties, excluding variant-forming property groups
        $commonPropertyIds = [];
        foreach ($propertyAnalysis['common'] as $groupId => $optionIds) {
            // Skip variant-forming property groups
            if (!in_array($groupId, $variantFormingPropertyGroupIds)) {
                $commonPropertyIds = array_merge($commonPropertyIds, $optionIds);
            }
        }

        // Set only common custom fields
        $commonCustomFields = $customFieldsAnalysis['common'];

        // Analyze common fields across all products
        $commonFields = $this->analyzeCommonFields($products);

        $updateData = [
            'id' => $parent->getId(),
        ];

        // Set common properties (excluding variant-forming)
        if (!empty($commonPropertyIds)) {
            $updateData['properties'] = array_map(function ($id) {
                return ['id' => $id];
            }, $commonPropertyIds);
        } else {
            $updateData['properties'] = [];
        }

        // Set common custom fields
        if (!empty($commonCustomFields)) {
            $updateData['customFields'] = $commonCustomFields;
        }

        // Copy common dimensions and weight if they match across all products
        if (isset($commonFields['width'])) {
            $updateData['width'] = $commonFields['width'];
        }
        if (isset($commonFields['height'])) {
            $updateData['height'] = $commonFields['height'];
        }
        if (isset($commonFields['length'])) {
            $updateData['length'] = $commonFields['length'];
        }
        if (isset($commonFields['weight'])) {
            $updateData['weight'] = $commonFields['weight'];
        }

        $this->productRepository->update([$updateData], $context);

        // Clear common fields from variants so they inherit from parent
        $this->clearCommonFieldsFromVariants($products, $commonFields, array_keys($commonCustomFields), $context);
    }

    /**
     * Clear common fields from variants so they inherit from parent
     */
    private function clearCommonFieldsFromVariants(array $products, array $commonFields, array $commonCustomFieldKeys, Context $context): void
    {
        $variantUpdates = [];

        foreach ($products as $product) {
            $updateData = [
                'id' => $product->getId()
            ];

            // Clear common dimensions and weight (only if they are common)
            if (isset($commonFields['width'])) {
                $updateData['width'] = null;
            }
            if (isset($commonFields['height'])) {
                $updateData['height'] = null;
            }
            if (isset($commonFields['length'])) {
                $updateData['length'] = null;
            }
            if (isset($commonFields['weight'])) {
                $updateData['weight'] = null;
            }
            if (isset($commonFields['minPurchase'])) {
                $updateData['minPurchase'] = null;
            }
            if (isset($commonFields['purchaseSteps'])) {
                $updateData['purchaseSteps'] = null;
            }

            // Clear common custom fields
            if (!empty($commonCustomFieldKeys)) {
                $customFields = $product->getCustomFields();
                if ($customFields) {
                    $updatedCustomFields = $customFields;
                    foreach ($commonCustomFieldKeys as $key) {
                        if (isset($updatedCustomFields[$key])) {
                            unset($updatedCustomFields[$key]);
                        }
                    }
                    $updateData['customFields'] = $updatedCustomFields;
                }
            }

            $variantUpdates[] = $updateData;
        }

        if (!empty($variantUpdates)) {
            $this->productRepository->update($variantUpdates, $context);
        }
    }

    /**
     * Analyze common fields across products (dimensions, weight, etc.)
     */
    private function analyzeCommonFields(array $products): array
    {
        $commonFields = [];
        $fieldNames = ['width', 'height', 'length', 'weight', 'minPurchase', 'purchaseSteps'];

        foreach ($fieldNames as $fieldName) {
            $values = [];
            $getter = 'get' . ucfirst($fieldName);

            foreach ($products as $product) {
                if (method_exists($product, $getter)) {
                    $value = $product->$getter();
                    if ($value !== null) {
                        $values[] = $value;
                    }
                }
            }

            // If all products have the same value for this field
            if (!empty($values) && count(array_unique($values)) === 1 && count($values) === count($products)) {
                $commonFields[$fieldName] = $values[0];
            }
        }

        return $commonFields;
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
    private function processMedia(ProductEntity $parent, array $products, Context $context): void
    {
        // Get first product's media
        $firstProduct = reset($products);
        if (!$firstProduct || !$firstProduct->getMedia()) {
            return;
        }

        // Collect all media IDs from first product
        $parentMediaIds = [];
        $parentCoverId = null;

        foreach ($firstProduct->getMedia() as $media) {
            $parentMediaIds[] = $media->getMediaId();
        }

        if ($firstProduct->getCover()) {
            $parentCoverId = $firstProduct->getCover()->getMediaId();
        }

        if (empty($parentMediaIds)) {
            return;
        }

        // Copy media to parent
        $parentUpdate = [
            'id' => $parent->getId(),
            'media' => array_map(function ($mediaId) {
                return ['mediaId' => $mediaId];
            }, $parentMediaIds)
        ];

        if ($parentCoverId) {
            $parentUpdate['cover'] = ['mediaId' => $parentCoverId];
        }

        $this->productRepository->update([$parentUpdate], $context);

        // Clear media from variants that are present in parent
        $variantUpdates = [];
        foreach ($products as $product) {
            $variantMediaIds = [];
            if ($product->getMedia()) {
                foreach ($product->getMedia() as $media) {
                    $variantMediaIds[] = $media->getMediaId();
                }
            }

            if (empty($variantMediaIds)) {
                continue;
            }

            // Find media IDs that are unique to this variant (not in parent)
            $uniqueMediaIds = array_diff($variantMediaIds, $parentMediaIds);

            // If variant has some media that's in parent, update it
            if (count($uniqueMediaIds) !== count($variantMediaIds)) {
                $updateData = [
                    'id' => $product->getId(),
                ];

                // Keep only unique media
                if (!empty($uniqueMediaIds)) {
                    $updateData['media'] = array_map(function ($mediaId) {
                        return ['mediaId' => $mediaId];
                    }, array_values($uniqueMediaIds));
                } else {
                    // All media is from parent, clear it
                    $updateData['media'] = [];
                }

                // Clear cover if it's in parent
                if ($product->getCover() && in_array($product->getCover()->getMediaId(), $parentMediaIds)) {
                    $updateData['coverId'] = null;
                }

                $variantUpdates[] = $updateData;
            }
        }

        if (!empty($variantUpdates)) {
            $this->productRepository->update($variantUpdates, $context);
        }
    }

    /**
     * Clear categories and visibilities from variants so they inherit from parent
     */
    private function clearVariantAssociations(array $products, Context $context): void
    {
        foreach ($products as $product) {
            // Delete all category associations for this variant
            if ($product->getCategories() && $product->getCategories()->count() > 0) {
                foreach ($product->getCategories() as $category) {
                    $this->connection->executeStatement(
                        'DELETE FROM product_category WHERE product_id = :productId AND category_id = :categoryId',
                        [
                            'productId' => Uuid::fromHexToBytes($product->getId()),
                            'categoryId' => Uuid::fromHexToBytes($category->getId())
                        ]
                    );
                }
            }

            // Delete all visibility associations for this variant
            if ($product->getVisibilities() && $product->getVisibilities()->count() > 0) {
                $this->connection->executeStatement(
                    'DELETE FROM product_visibility WHERE product_id = :productId',
                    ['productId' => Uuid::fromHexToBytes($product->getId())]
                );
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
}

