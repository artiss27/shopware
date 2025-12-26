<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\PriceUpdate;

use Artiss\Supplier\Core\Content\PriceTemplate\PriceTemplateEntity;
use Artiss\Supplier\Service\Calculator\PriceCalculator;
use Artiss\Supplier\Service\Matcher\MatcherChain;
use Artiss\Supplier\Service\Parser\ParserRegistry;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Main service for price update workflow
 */
class PriceUpdateService
{
    public function __construct(
        private readonly ParserRegistry $parserRegistry,
        private readonly MatcherChain $matcherChain,
        private readonly PriceCalculator $priceCalculator,
        private readonly EntityRepository $priceTemplateRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    /**
     * Parse and normalize price list file
     *
     * @param string $templateId Price template ID
     * @param string $mediaId Media file ID
     * @param bool $forceRefresh Force re-parsing even if cached
     * @param Context $context
     *
     * @return array Normalized data
     */
    public function parseAndNormalize(
        string $templateId,
        string $mediaId,
        bool $forceRefresh,
        Context $context
    ): array {
        $template = $this->getTemplate($templateId, $context);
        $media = $this->getMedia($mediaId, $context);

        // Check if we can use cached data
        if (!$forceRefresh
            && $template->getLastImportMediaId() === $mediaId
            && $template->getLastImportMediaUpdatedAt() === $media->getUpdatedAt()
            && $template->getNormalizedData() !== null
        ) {
            return json_decode($template->getNormalizedData(), true);
        }

        // Parse file
        $config = $template->getConfig()['mapping'] ?? [];
        $normalizedData = $this->parserRegistry->parse($media, $config);

        // Save normalized data to template
        $this->priceTemplateRepository->update([
            [
                'id' => $templateId,
                'normalizedData' => json_encode($normalizedData),
                'lastImportMediaId' => $mediaId,
                'lastImportMediaUpdatedAt' => $media->getUpdatedAt(),
            ],
        ], $context);

        return $normalizedData;
    }

    /**
     * Match products and calculate prices for preview
     *
     * @param string $templateId Price template ID
     * @param Context $context
     *
     * @return array Preview data with matched/unmatched products
     */
    public function matchProductsPreview(string $templateId, Context $context): array
    {
        $template = $this->getTemplate($templateId, $context);

        // Get normalized data
        $normalizedData = $template->getNormalizedData();
        if ($normalizedData === null) {
            throw new \RuntimeException('No normalized data available. Please parse the file first.');
        }

        $priceData = json_decode($normalizedData, true);

        // Get filtered products
        $products = $this->getFilteredProducts($template, $context);

        // Get existing matched products mapping
        $matchedProductsMap = $template->getMatchedProducts() ?? [];

        // Match products
        $matchResult = $this->matcherChain->matchAll(
            $priceData,
            $products,
            $matchedProductsMap,
            $template->getSupplierId()
        );

        // Calculate prices for matched products
        $priceRules = $template->getConfig()['price_rules'] ?? [];
        $previewData = [];

        foreach ($matchResult['matched'] as $matchedItem) {
            $calculated = $this->priceCalculator->calculate($matchedItem, $priceRules);
            $product = $products->get($matchedItem['matched']['product_id']);

            $previewData[] = [
                'supplier_code' => $matchedItem['code'],
                'supplier_name' => $matchedItem['name'],
                'product_id' => $matchedItem['matched']['product_id'],
                'product_name' => $product?->getTranslated()['name'] ?? $product?->getName() ?? '',
                'current_purchase_price' => $product?->getPurchasePrices()?->first()?->getGross() ?? null,
                'current_retail_price' => $product?->getPrice()?->first()?->getGross() ?? null,
                'new_purchase_price' => $this->priceCalculator->roundPrice($calculated['purchase_price']),
                'new_retail_price' => $this->priceCalculator->roundPrice($calculated['retail_price']),
                'confidence' => $matchedItem['matched']['confidence'],
                'method' => $matchedItem['matched']['method'],
                'is_confirmed' => false,
            ];
        }

        // Add unmatched items
        $unmatchedData = [];
        foreach ($matchResult['unmatched'] as $unmatchedItem) {
            $calculated = $this->priceCalculator->calculate($unmatchedItem, $priceRules);
            $unmatchedData[] = [
                'supplier_code' => $unmatchedItem['code'],
                'supplier_name' => $unmatchedItem['name'],
                'product_id' => null,
                'product_name' => null,
                'new_purchase_price' => $this->priceCalculator->roundPrice($calculated['purchase_price']),
                'new_retail_price' => $this->priceCalculator->roundPrice($calculated['retail_price']),
                'confidence' => 'none',
                'method' => null,
                'is_confirmed' => false,
            ];
        }

        return [
            'matched' => $previewData,
            'unmatched' => $unmatchedData,
            'stats' => $matchResult['stats'],
        ];
    }

    /**
     * Update matched product manually
     *
     * @param string $templateId Template ID
     * @param string $productId Product ID to match
     * @param string $supplierCode Supplier code from price list
     * @param Context $context
     */
    public function updateMatchedProduct(
        string $templateId,
        string $productId,
        string $supplierCode,
        Context $context
    ): void {
        $template = $this->getTemplate($templateId, $context);
        $matchedProducts = $template->getMatchedProducts() ?? [];

        // Update mapping
        $matchedProducts[$productId] = $supplierCode;

        $this->priceTemplateRepository->update([
            [
                'id' => $templateId,
                'matchedProducts' => $matchedProducts,
            ],
        ], $context);
    }

    /**
     * Apply prices to products
     *
     * @param string $templateId Template ID
     * @param array $confirmedMatches Array of confirmed matches to apply
     * @param string $userId User ID who applies the changes
     * @param Context $context
     *
     * @return array Result with stats
     */
    public function applyPrices(
        string $templateId,
        array $confirmedMatches,
        string $userId,
        Context $context
    ): array {
        $template = $this->getTemplate($templateId, $context);
        $matchedProducts = $template->getMatchedProducts() ?? [];

        $updateData = [];
        $stats = [
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($confirmedMatches as $match) {
            if (!$match['is_confirmed']) {
                $stats['skipped']++;
                continue;
            }

            $productId = $match['product_id'];
            $supplierCode = $match['supplier_code'];

            // Save to matched_products mapping
            $matchedProducts[$productId] = $supplierCode;

            // Prepare product update
            $updateData[] = [
                'id' => $productId,
                'purchasePrices' => [
                    [
                        'currencyId' => $context->getCurrencyId(),
                        'gross' => $match['new_purchase_price'],
                        'net' => $match['new_purchase_price'],
                        'linked' => false,
                    ],
                ],
                'price' => [
                    [
                        'currencyId' => $context->getCurrencyId(),
                        'gross' => $match['new_retail_price'],
                        'net' => $match['new_retail_price'],
                        'linked' => false,
                    ],
                ],
                'customFields' => [
                    'supplier_code' => $supplierCode,
                ],
            ];

            $stats['updated']++;
        }

        // Update products
        if (!empty($updateData)) {
            try {
                $this->productRepository->update($updateData, $context);
            } catch (\Exception $e) {
                $stats['failed'] = count($updateData);
                $stats['updated'] = 0;
                throw $e;
            }
        }

        // Update template
        $this->priceTemplateRepository->update([
            [
                'id' => $templateId,
                'matchedProducts' => $matchedProducts,
                'appliedAt' => new \DateTime(),
                'appliedByUserId' => $userId,
            ],
        ], $context);

        return $stats;
    }

    /**
     * Get filtered products based on template filters
     */
    private function getFilteredProducts(PriceTemplateEntity $template, Context $context): ProductCollection
    {
        $filters = $template->getConfig()['filters'] ?? [];
        $criteria = new Criteria();

        // Add filters
        if (!empty($filters['categories'])) {
            $criteria->addFilter(new EqualsAnyFilter('categoryIds', $filters['categories']));
        }

        if (!empty($filters['manufacturers'])) {
            $criteria->addFilter(new EqualsAnyFilter('productManufacturerId', $filters['manufacturers']));
        }

        if (!empty($filters['equipment_types'])) {
            // Equipment types are stored in properties
            // This would need custom implementation based on your property structure
        }

        // Load associations needed for matching
        $criteria->addAssociation('children');
        $criteria->setLimit(10000); // Large limit for all products

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        return $products;
    }

    private function getTemplate(string $templateId, Context $context): PriceTemplateEntity
    {
        $criteria = new Criteria([$templateId]);
        $criteria->addAssociation('supplier');
        $criteria->addAssociation('lastImportMedia');

        $template = $this->priceTemplateRepository->search($criteria, $context)->first();

        if ($template === null) {
            throw new \RuntimeException("Price template not found: {$templateId}");
        }

        return $template;
    }

    private function getMedia(string $mediaId, Context $context)
    {
        $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->first();

        if ($media === null) {
            throw new \RuntimeException("Media file not found: {$mediaId}");
        }

        return $media;
    }
}
