<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\PriceUpdate;

use Artiss\Supplier\Core\Content\PriceTemplate\PriceTemplateEntity;
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
        private readonly EntityRepository $priceTemplateRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    /**
     * Parse and normalize price list file using new column mapping structure
     *
     * @param string $templateId Price template ID
     * @param string $mediaId Media file ID (optional, uses selected_media_id from config if not provided)
     * @param bool $forceRefresh Force re-parsing even if cached
     * @param Context $context
     *
     * @return array Normalized data
     */
    public function parseAndNormalize(
        string $templateId,
        ?string $mediaId,
        bool $forceRefresh,
        Context $context
    ): array {
        $template = $this->getTemplate($templateId, $context);
        $config = $template->getConfig();

        // Use selected_media_id from config if mediaId not provided
        $mediaId = $mediaId ?? ($config['selected_media_id'] ?? null);

        if (!$mediaId) {
            throw new \RuntimeException('No media file selected in template');
        }

        $media = $this->getMedia($mediaId, $context);

        // Check if we can use cached data
        if (!$forceRefresh
            && $template->getLastImportMediaId() === $mediaId
            && $template->getLastImportMediaUpdatedAt() === $media->getUpdatedAt()
            && $template->getNormalizedData() !== null
        ) {
            return json_decode($template->getNormalizedData(), true);
        }

        // Build parser config from new structure
        $parserConfig = [
            'start_row' => $config['start_row'] ?? 2,
            'column_mapping' => $config['column_mapping'] ?? [],
        ];

        // Parse file with new column mapping structure
        $normalizedData = $this->parseWithColumnMapping($media, $parserConfig);

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
     * Parse file with column mapping
     */
    private function parseWithColumnMapping($media, array $config): array
    {
        // Get file extension and appropriate parser
        $parser = $this->parserRegistry->getParser($media);

        if (!$parser) {
            throw new \RuntimeException('No parser found for file type');
        }

        // Parse file using the existing parser's parse method
        // Pass the config directly - parsers will handle the new column_mapping structure
        $rawData = $parser->parse($media, $config);

        return $rawData;
    }

    /**
     * Match products and calculate prices for preview with new config structure
     *
     * @param string $templateId Price template ID
     * @param Context $context
     *
     * @return array Preview data with matched/unmatched products
     */
    public function matchProductsPreview(string $templateId, Context $context): array
    {
        $template = $this->getTemplate($templateId, $context);
        $config = $template->getConfig();

        // Get normalized data (parse if needed)
        $normalizedData = $template->getNormalizedData();
        if ($normalizedData === null) {
            // Auto-parse if not done yet
            $normalizedData = json_encode($this->parseAndNormalize($templateId, null, false, $context));
        }

        $priceData = json_decode($normalizedData, true);

        // Get filtered products
        $products = $this->getFilteredProducts($template, $context);

        // Get existing matched products mapping
        $matchedProductsMap = $template->getMatchedProducts() ?? [];

        // Match products using new matching logic
        $matchResult = $this->matchProductsNew($priceData, $products, $matchedProductsMap, $template);

        // Calculate prices with modifiers
        $modifiers = $config['modifiers'] ?? [];
        $currencies = $config['price_currencies'] ?? [
            'purchase' => 'UAH',
            'retail' => 'UAH',
            'list' => 'UAH',
        ];

        $previewData = [];

        foreach ($matchResult['matched'] as $matchedItem) {
            $product = $products->get($matchedItem['product_id']);
            $priceListData = $matchedItem['price_data'];

            // Apply modifiers to calculate final prices
            $calculatedPrices = $this->applyModifiers($priceListData, $modifiers);

            // Get current prices from custom fields
            $customFields = $product?->getCustomFields() ?? [];
            $currentPurchasePrice = $customFields['product_prices']['purchase_price_value'] ?? null;
            $currentRetailPrice = $customFields['product_prices']['retail_price_value'] ?? null;
            $currentListPrice = $customFields['product_prices']['list_price_value'] ?? null;

            $previewData[] = [
                'supplier_code' => $matchedItem['code'],
                'supplier_name' => $priceListData['name'] ?? '',
                'product_id' => $matchedItem['product_id'],
                'product_name' => $product?->getTranslated()['name'] ?? $product?->getName() ?? '',
                'product_number' => $product?->getProductNumber() ?? '',
                'current_prices' => [
                    'purchase' => $currentPurchasePrice,
                    'retail' => $currentRetailPrice,
                    'list' => $currentListPrice,
                ],
                'new_prices' => [
                    'purchase' => $calculatedPrices['purchase'],
                    'retail' => $calculatedPrices['retail'],
                    'list' => $calculatedPrices['list'],
                ],
                'currencies' => $currencies,
                'price_changes' => [
                    'purchase' => $this->getPriceChange($currentPurchasePrice, $calculatedPrices['purchase']),
                    'retail' => $this->getPriceChange($currentRetailPrice, $calculatedPrices['retail']),
                    'list' => $this->getPriceChange($currentListPrice, $calculatedPrices['list']),
                ],
                'confidence' => $matchedItem['confidence'],
                'method' => $matchedItem['method'],
                'is_confirmed' => $matchedItem['is_confirmed'] ?? false,
            ];
        }

        // Add unmatched items
        $unmatchedData = [];
        foreach ($matchResult['unmatched'] as $unmatchedItem) {
            $calculatedPrices = $this->applyModifiers($unmatchedItem, $modifiers);

            $unmatchedData[] = [
                'supplier_code' => $unmatchedItem['code'] ?? '',
                'supplier_name' => $unmatchedItem['name'] ?? '',
                'product_id' => null,
                'product_name' => null,
                'new_prices' => [
                    'purchase' => $calculatedPrices['purchase'],
                    'retail' => $calculatedPrices['retail'],
                    'list' => $calculatedPrices['list'],
                ],
                'currencies' => $currencies,
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
     * Match products using new logic: matched_products -> kod_postavschika -> name similarity
     */
    private function matchProductsNew(array $priceData, ProductCollection $products, array $matchedProductsMap, PriceTemplateEntity $template): array
    {
        $matched = [];
        $unmatched = [];
        $stats = [
            'total' => count($priceData),
            'matched_exact' => 0,
            'matched_code' => 0,
            'matched_name' => 0,
            'unmatched' => 0,
        ];

        foreach ($priceData as $item) {
            $code = $item['code'] ?? '';
            $matchResult = null;

            // 1. Try matched_products map first
            foreach ($matchedProductsMap as $productId => $supplierCode) {
                if ($supplierCode === $code) {
                    $product = $products->get($productId);
                    if ($product) {
                        $matchResult = [
                            'product_id' => $productId,
                            'confidence' => 'exact',
                            'method' => 'matched_products',
                            'is_confirmed' => true,
                        ];
                        $stats['matched_exact']++;
                        break;
                    }
                }
            }

            // 2. Try kod_postavschika from custom fields
            if (!$matchResult) {
                foreach ($products as $product) {
                    $customFields = $product->getCustomFields() ?? [];
                    $kodPostavschika = $customFields['kod_postavschika'] ?? '';
                    if ($kodPostavschika && $kodPostavschika === $code) {
                        $matchResult = [
                            'product_id' => $product->getId(),
                            'confidence' => 'high',
                            'method' => 'kod_postavschika',
                            'is_confirmed' => false, // Requires confirmation
                        ];
                        $stats['matched_code']++;
                        break;
                    }
                }
            }

            // 3. Try name similarity (simplified for now - can be enhanced with Levenshtein)
            if (!$matchResult && !empty($item['name'])) {
                $supplierName = mb_strtolower(trim($item['name']));
                foreach ($products as $product) {
                    $productName = mb_strtolower(trim($product->getTranslated()['name'] ?? $product->getName() ?? ''));
                    if ($supplierName && $productName && str_contains($productName, $supplierName)) {
                        $matchResult = [
                            'product_id' => $product->getId(),
                            'confidence' => 'medium',
                            'method' => 'name_similarity',
                            'is_confirmed' => false, // Requires confirmation
                        ];
                        $stats['matched_name']++;
                        break;
                    }
                }
            }

            if ($matchResult) {
                $matched[] = array_merge($item, [
                    'code' => $code,
                    'price_data' => $item,
                ], $matchResult);
            } else {
                $unmatched[] = $item;
                $stats['unmatched']++;
            }
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'stats' => $stats,
        ];
    }

    /**
     * Apply modifiers to calculate final prices
     */
    private function applyModifiers(array $priceData, array $modifiers): array
    {
        $prices = [
            'purchase' => $priceData['purchase_price'] ?? null,
            'retail' => $priceData['retail_price'] ?? null,
            'list' => $priceData['list_price'] ?? null,
        ];

        foreach ($modifiers as $modifier) {
            $priceType = $modifier['price_type'] ?? null;
            $modifierType = $modifier['modifier_type'] ?? 'none';
            $value = (float) ($modifier['value'] ?? 0);

            if (!$priceType || $modifierType === 'none' || !isset($prices[$priceType])) {
                continue;
            }

            $originalPrice = (float) $prices[$priceType];

            if ($modifierType === 'percentage') {
                $prices[$priceType] = $originalPrice * (1 + $value / 100);
            } elseif ($modifierType === 'fixed') {
                $prices[$priceType] = $originalPrice + $value;
            }

            // Round to 2 decimals
            $prices[$priceType] = round($prices[$priceType], 2);
        }

        return $prices;
    }

    /**
     * Get price change indicator: 'increase', 'decrease', or 'unchanged'
     */
    private function getPriceChange(?float $oldPrice, ?float $newPrice): string
    {
        if ($oldPrice === null || $newPrice === null) {
            return 'new';
        }

        if ($newPrice > $oldPrice) {
            return 'increase';
        }

        if ($newPrice < $oldPrice) {
            return 'decrease';
        }

        return 'unchanged';
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
     * Apply prices to products using new custom fields structure
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
        $config = $template->getConfig();
        $matchedProducts = $template->getMatchedProducts() ?? [];

        // Get currencies from config
        $currencies = $config['price_currencies'] ?? [
            'purchase' => 'UAH',
            'retail' => 'UAH',
            'list' => 'UAH',
        ];

        $updateData = [];
        $stats = [
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($confirmedMatches as $match) {
            if (!isset($match['is_confirmed']) || !$match['is_confirmed']) {
                $stats['skipped']++;
                continue;
            }

            $productId = $match['product_id'];
            $supplierCode = $match['supplier_code'];
            $newPrices = $match['new_prices'] ?? [];

            // Save to matched_products mapping
            $matchedProducts[$productId] = $supplierCode;

            // Prepare custom fields update with new price structure
            $productPrices = [];

            if (isset($newPrices['purchase']) && $newPrices['purchase'] !== null) {
                $productPrices['purchase_price_value'] = $newPrices['purchase'];
                $productPrices['purchase_price_currency'] = $currencies['purchase'];
            }

            if (isset($newPrices['retail']) && $newPrices['retail'] !== null) {
                $productPrices['retail_price_value'] = $newPrices['retail'];
                $productPrices['retail_price_currency'] = $currencies['retail'];
            }

            if (isset($newPrices['list']) && $newPrices['list'] !== null) {
                $productPrices['list_price_value'] = $newPrices['list'];
                $productPrices['list_price_currency'] = $currencies['list'];
            }

            // Update product custom fields
            $updateData[] = [
                'id' => $productId,
                'customFields' => [
                    'product_prices' => $productPrices,
                    'kod_postavschika' => $supplierCode,
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
     * Auto-match products by name similarity for unmatched items
     */
    public function autoMatchProducts(string $templateId, Context $context): array
    {
        $template = $this->getTemplate($templateId, $context);
        $matchedProductsMap = $template->getMatchedProducts() ?? [];

        // Get preview to find unmatched items
        $preview = $this->matchProductsPreview($templateId, $context);
        $products = $this->getFilteredProducts($template, $context);

        $autoMatched = [];
        $stats = [
            'total_unmatched' => count($preview['unmatched']),
            'auto_matched' => 0,
            'still_unmatched' => 0,
        ];

        // Try to match unmatched items by name similarity
        foreach ($preview['unmatched'] as $unmatchedItem) {
            $supplierName = mb_strtolower(trim($unmatchedItem['supplier_name'] ?? ''));
            if (!$supplierName) {
                $stats['still_unmatched']++;
                continue;
            }

            $bestMatch = null;
            $bestSimilarity = 0;

            foreach ($products as $product) {
                $productName = mb_strtolower(trim($product->getTranslated()['name'] ?? $product->getName() ?? ''));
                if (!$productName) {
                    continue;
                }

                // Calculate similarity using simple contains check and levenshtein
                if (str_contains($productName, $supplierName) || str_contains($supplierName, $productName)) {
                    $similarity = 0.8; // High similarity for contains

                    // Use levenshtein for more precise matching
                    $lev = levenshtein(substr($supplierName, 0, 255), substr($productName, 0, 255));
                    $maxLen = max(strlen($supplierName), strlen($productName));
                    $levSimilarity = 1 - ($lev / $maxLen);

                    $similarity = max($similarity, $levSimilarity);

                    if ($similarity > $bestSimilarity && $similarity > 0.6) { // Threshold 60%
                        $bestSimilarity = $similarity;
                        $bestMatch = [
                            'product_id' => $product->getId(),
                            'product_name' => $product->getTranslated()['name'] ?? $product->getName(),
                            'confidence' => $similarity > 0.8 ? 'high' : 'medium',
                            'similarity' => round($similarity * 100, 1),
                        ];
                    }
                }
            }

            if ($bestMatch) {
                $autoMatched[] = [
                    'supplier_code' => $unmatchedItem['supplier_code'],
                    'supplier_name' => $unmatchedItem['supplier_name'],
                    'product_id' => $bestMatch['product_id'],
                    'product_name' => $bestMatch['product_name'],
                    'confidence' => $bestMatch['confidence'],
                    'similarity' => $bestMatch['similarity'],
                    'is_confirmed' => false, // Requires manual confirmation
                ];
                $stats['auto_matched']++;
            } else {
                $stats['still_unmatched']++;
            }
        }

        return [
            'matches' => $autoMatched,
            'stats' => $stats,
        ];
    }

    /**
     * Confirm all pending matches (auto-matched or manually matched)
     */
    public function confirmAllMatches(string $templateId, array $matchesToConfirm, Context $context): array
    {
        $template = $this->getTemplate($templateId, $context);
        $matchedProductsMap = $template->getMatchedProducts() ?? [];

        $confirmed = 0;
        foreach ($matchesToConfirm as $match) {
            $productId = $match['product_id'] ?? null;
            $supplierCode = $match['supplier_code'] ?? null;

            if ($productId && $supplierCode) {
                $matchedProductsMap[$productId] = $supplierCode;
                $confirmed++;
            }
        }

        // Save updated matched_products map
        $this->priceTemplateRepository->update([
            [
                'id' => $templateId,
                'matchedProducts' => $matchedProductsMap,
            ],
        ], $context);

        return [
            'confirmed' => $confirmed,
            'total_mappings' => count($matchedProductsMap),
        ];
    }

    /**
     * Get filtered products based on template filters
     */
    private function getFilteredProducts(PriceTemplateEntity $template, Context $context): ProductCollection
    {
        $filters = $template->getConfig()['filters'] ?? [];
        $criteria = new Criteria();

        // Add filters
        if (!empty($filters['categories']) && is_array($filters['categories'])) {
            $criteria->addFilter(new EqualsAnyFilter('categoryIds', $filters['categories']));
        }

        if (!empty($filters['manufacturers']) && is_array($filters['manufacturers'])) {
            $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $filters['manufacturers']));
        }

        if (!empty($filters['equipment_types']) && is_array($filters['equipment_types'])) {
            // Equipment types are stored in custom fields
            $criteria->addFilter(new EqualsAnyFilter('customFields.equipment_type', $filters['equipment_types']));
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

    /**
     * Recalculate product prices from custom fields using current exchange rates
     * This method is called from the admin UI and provides web-friendly response
     */
    public function recalculatePricesFromCustomFields(
        string $priceType,
        ?int $limit,
        Context $context
    ): array {
        // Load all currencies with current exchange rates
        $currencies = $this->loadCurrencies($context);

        // Find products with custom price fields
        $products = $this->findProductsWithCustomPrices($context, $limit);

        if (empty($products)) {
            return [
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];
        }

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $updateData = [];

        foreach ($products as $product) {
            $customFields = $product->getCustomFields() ?? [];
            $productPrices = $customFields['product_prices'] ?? [];

            if (empty($productPrices)) {
                $stats['skipped']++;
                continue;
            }

            $update = $this->calculatePriceUpdateFromCustomFields(
                $product,
                $productPrices,
                $currencies,
                $priceType,
                $context
            );

            if ($update) {
                $updateData[] = $update;
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            $stats['processed']++;

            // Batch update every 100 products
            if (count($updateData) >= 100) {
                try {
                    $this->productRepository->update($updateData, $context);
                    $updateData = [];
                } catch (\Exception $e) {
                    $stats['errors'] += count($updateData);
                    $stats['updated'] -= count($updateData);
                    $updateData = [];
                }
            }
        }

        // Final batch update
        if (!empty($updateData)) {
            try {
                $this->productRepository->update($updateData, $context);
            } catch (\Exception $e) {
                $stats['errors'] += count($updateData);
                $stats['updated'] -= count($updateData);
            }
        }

        return $stats;
    }

    /**
     * Load all currencies with exchange rates
     */
    private function loadCurrencies(Context $context): array
    {
        $currencyCriteria = new Criteria();
        $currencies = $this->productRepository->getDefinition()
            ->getEntityManager()
            ->getRepository('currency')
            ->search($currencyCriteria, $context);

        $currencyMap = [];
        foreach ($currencies as $currency) {
            $currencyMap[$currency->getIsoCode()] = $currency->getFactor();
        }

        return $currencyMap;
    }

    /**
     * Find products that have custom price fields
     */
    private function findProductsWithCustomPrices(Context $context, ?int $limit): array
    {
        $criteria = new Criteria();

        // Filter products that have product_prices custom field
        $criteria->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter::CONNECTION_AND,
                [new EqualsFilter('customFields.product_prices', null)]
            )
        );

        if ($limit) {
            $criteria->setLimit($limit);
        } else {
            $criteria->setLimit(5000); // Safe default limit
        }

        return $this->productRepository->search($criteria, $context)->getElements();
    }

    /**
     * Calculate price update for a product based on custom fields
     */
    private function calculatePriceUpdateFromCustomFields(
        $product,
        array $productPrices,
        array $currencies,
        string $priceType,
        Context $context
    ): ?array {
        $updates = [
            'id' => $product->getId(),
        ];

        $hasUpdates = false;
        $defaultCurrencyId = \Shopware\Core\Defaults::CURRENCY;

        // Process retail price (main product price)
        if (($priceType === 'retail' || $priceType === 'all')
            && isset($productPrices['retail_price_value'])
            && isset($productPrices['retail_price_currency'])
        ) {
            $retailValue = (float) $productPrices['retail_price_value'];
            $retailCurrency = $productPrices['retail_price_currency'];
            $factor = $currencies[$retailCurrency] ?? 1.0;

            // Convert to base currency
            $basePrice = $retailValue / $factor;

            $updates['price'] = [
                [
                    'currencyId' => $defaultCurrencyId,
                    'gross' => round($basePrice, 2),
                    'net' => round($basePrice, 2),
                    'linked' => false,
                ],
            ];
            $hasUpdates = true;
        }

        // Process purchase price
        if (($priceType === 'purchase' || $priceType === 'all')
            && isset($productPrices['purchase_price_value'])
            && isset($productPrices['purchase_price_currency'])
        ) {
            $purchaseValue = (float) $productPrices['purchase_price_value'];
            $purchaseCurrency = $productPrices['purchase_price_currency'];
            $factor = $currencies[$purchaseCurrency] ?? 1.0;

            $basePrice = $purchaseValue / $factor;

            $updates['purchasePrices'] = [
                [
                    'currencyId' => $defaultCurrencyId,
                    'gross' => round($basePrice, 2),
                    'net' => round($basePrice, 2),
                    'linked' => false,
                ],
            ];
            $hasUpdates = true;
        }

        return $hasUpdates ? $updates : null;
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
