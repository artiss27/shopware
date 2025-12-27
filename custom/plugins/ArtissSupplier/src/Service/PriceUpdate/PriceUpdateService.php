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
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $currencyRepository
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
        $columnMapping = $config['column_mapping'] ?? [];
        
        // Check if column mapping is configured
        if (empty($columnMapping)) {
            throw new \RuntimeException('Column mapping is not configured. Please configure column mapping on step 2 before preview.');
        }
        
        $parserConfig = [
            'start_row' => $config['start_row'] ?? 2,
            'column_mapping' => $columnMapping,
        ];

        // Parse file with new column mapping structure
        $normalizedData = $this->parseWithColumnMapping($media, $parserConfig);
        
        // Check if parsing returned data
        if (empty($normalizedData)) {
            throw new \RuntimeException('No data found in price list. Please check column mapping and start row configuration.');
        }

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
     * Returns ALL products from filters, not just matched ones
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
        $priceData = [];

        if ($normalizedData !== null && $normalizedData !== '') {
            $priceData = json_decode($normalizedData, true);
            if (!is_array($priceData)) {
                $priceData = [];
            }
        }

        // If no data, try to parse
        if (empty($priceData) && $config['selected_media_id']) {
            try {
                $priceData = $this->parseAndNormalize(
                    $templateId,
                    $config['selected_media_id'],
                    false,
                    $context
                );
            } catch (\Exception $e) {
                // If parsing fails, continue with empty data
                $priceData = [];
            }
        }

        // Get filtered products - this is the main source of data
        $products = $this->getFilteredProducts($template, $context);

        // Get existing matched products mapping
        $matchedProductsMap = $template->getMatchedProducts() ?? [];

        // Calculate prices with modifiers
        $modifiers = $config['modifiers'] ?? [];
        $currencies = $config['price_currencies'] ?? [
            'purchase' => 'UAH',
            'retail' => 'UAH',
            'list' => 'UAH',
        ];

        // Build a map of price data by code for quick lookup
        $priceDataByCode = [];
        foreach ($priceData as $item) {
            $code = $item['code'] ?? '';
            if ($code) {
                $priceDataByCode[$code] = $item;
            }
        }

        $previewData = [];
        $matchedCount = 0;
        $unmatchedCount = 0;

        // Process ALL products from filters
        foreach ($products as $product) {
            $productId = $product->getId();
            $customFields = $product->getCustomFields() ?? [];
            $kodPostavschika = $customFields['kod_postavschika'] ?? '';

            // Try to find matching price data
            $matchedPriceData = null;
            $confidence = 'none';
            $method = null;
            $isConfirmed = false;
            $supplierCode = '';
            $supplierName = '';

            // 1. Check matched_products map
            if (isset($matchedProductsMap[$productId])) {
                $supplierCode = $matchedProductsMap[$productId];
                if (isset($priceDataByCode[$supplierCode])) {
                    $matchedPriceData = $priceDataByCode[$supplierCode];
                    $confidence = 'exact';
                    $method = 'matched_products';
                    $isConfirmed = true;
                    $matchedCount++;
                }
            }

            // 2. Try kod_postavschika
            if (!$matchedPriceData && $kodPostavschika) {
                $normalizedKod = strtoupper(trim($kodPostavschika));
                if (isset($priceDataByCode[$normalizedKod])) {
                    $matchedPriceData = $priceDataByCode[$normalizedKod];
                    $supplierCode = $normalizedKod;
                    $confidence = 'high';
                    $method = 'kod_postavschika';
                    $isConfirmed = false;
                    $matchedCount++;
                }
            }

            // 3. Try name similarity
            if (!$matchedPriceData && !empty($product->getName())) {
                $productName = mb_strtolower(trim($product->getTranslated()['name'] ?? $product->getName() ?? ''));
                foreach ($priceDataByCode as $code => $priceItem) {
                    $supplierName = mb_strtolower(trim($priceItem['name'] ?? ''));
                    if ($supplierName && $productName && str_contains($productName, $supplierName)) {
                        $matchedPriceData = $priceItem;
                        $supplierCode = $code;
                        $confidence = 'medium';
                        $method = 'name_similarity';
                        $isConfirmed = false;
                        $matchedCount++;
                        break;
                    }
                }
            }

            // Get current prices from custom fields (flat structure)
            $currentPurchasePrice = $customFields['purchase_price_value'] ?? null;
            $currentRetailPrice = $customFields['retail_price_value'] ?? null;
            $currentListPrice = $customFields['list_price_value'] ?? null;

            // Calculate new prices
            $newPrices = [
                'purchase' => null,
                'retail' => null,
                'list' => null,
            ];

            $availability = null;
            if ($matchedPriceData) {
                $calculatedPrices = $this->applyModifiers($matchedPriceData, $modifiers);
                $newPrices = $calculatedPrices;
                $supplierName = $matchedPriceData['name'] ?? '';
                $availability = $matchedPriceData['availability'] ?? null;
            } else {
                // If no match, don't set supplier name
                $supplierName = '';
                $unmatchedCount++;
            }

            $previewData[] = [
                'supplier_code' => $supplierCode,
                'supplier_name' => $supplierName,
                'product_id' => $productId,
                'product_name' => $product->getTranslated()['name'] ?? $product->getName() ?? '',
                'current_kod_postavschika' => $kodPostavschika,
                'current_prices' => [
                    'purchase' => $currentPurchasePrice,
                    'retail' => $currentRetailPrice,
                    'list' => $currentListPrice,
                ],
                'new_prices' => $newPrices,
                'currencies' => $currencies,
                'price_changes' => [
                    'purchase' => $this->getPriceChange($currentPurchasePrice, $newPrices['purchase']),
                    'retail' => $this->getPriceChange($currentRetailPrice, $newPrices['retail']),
                    'list' => $this->getPriceChange($currentListPrice, $newPrices['list']),
                ],
                'availability' => $availability,
                'current_stock' => $product->getStock() ?? 0,
                'confidence' => $confidence,
                'method' => $method,
                'is_confirmed' => $isConfirmed,
                'status' => $matchedPriceData ? 'matched' : 'unmatched',
            ];
        }

        // Also add unmatched price list items (items from price list that don't match any product)
        $unmatchedPriceItems = [];
        $matchedCodes = [];
        foreach ($previewData as $item) {
            if (!empty($item['supplier_code'])) {
                $matchedCodes[] = $item['supplier_code'];
            }
        }

        foreach ($priceData as $priceItem) {
            $code = $priceItem['code'] ?? '';
            if ($code && !in_array($code, $matchedCodes, true)) {
                $calculatedPrices = $this->applyModifiers($priceItem, $modifiers);
                $unmatchedPriceItems[] = [
                    'supplier_code' => $code,
                    'supplier_name' => $priceItem['name'] ?? '',
                    'product_id' => null,
                    'product_name' => null,
                    'product_number' => null,
                    'current_prices' => [
                        'purchase' => null,
                        'retail' => null,
                        'list' => null,
                    ],
                    'new_prices' => $calculatedPrices,
                    'currencies' => $currencies,
                    'price_changes' => [
                        'purchase' => null,
                        'retail' => null,
                        'list' => null,
                    ],
                    'confidence' => 'none',
                    'method' => null,
                    'is_confirmed' => false,
                    'status' => 'unmatched',
                ];
            }
        }

        return [
            'matched' => $previewData,
            'unmatched' => $unmatchedPriceItems,
            'stats' => [
                'total' => count($products),
                'matched_exact' => count(array_filter($previewData, fn($item) => $item['method'] === 'matched_products')),
                'matched_code' => count(array_filter($previewData, fn($item) => $item['method'] === 'kod_postavschika')),
                'matched_name' => count(array_filter($previewData, fn($item) => $item['method'] === 'name_similarity')),
                'unmatched' => $unmatchedCount + count($unmatchedPriceItems),
            ],
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
            $value = (float) ($modifier['modifier_value'] ?? $modifier['value'] ?? 0);

            if (!$priceType || $modifierType === 'none' || !isset($prices[$priceType]) || $prices[$priceType] === null) {
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

            // Prepare custom fields update - FLAT structure (not nested)
            // Custom fields in admin are configured as flat: purchase_price_value, not product_prices.purchase_price_value
            $customFields = [
                'kod_postavschika' => $supplierCode,
            ];

            if (isset($newPrices['purchase']) && $newPrices['purchase'] !== null) {
                $customFields['purchase_price_value'] = $newPrices['purchase'];
                $customFields['purchase_price_currency'] = $currencies['purchase'];
            }

            if (isset($newPrices['retail']) && $newPrices['retail'] !== null) {
                $customFields['retail_price_value'] = $newPrices['retail'];
                $customFields['retail_price_currency'] = $currencies['retail'];
            }

            if (isset($newPrices['list']) && $newPrices['list'] !== null) {
                $customFields['list_price_value'] = $newPrices['list'];
                $customFields['list_price_currency'] = $currencies['list'];
            }

            // Prepare update data - write to customFields in current context language
            $productUpdate = [
                'id' => $productId,
                'customFields' => $customFields,
            ];

            // Handle stock/availability update
            $availabilityAction = $config['filters']['availability_action'] ?? 'dont_change';
            $columnMapping = $config['column_mapping'] ?? [];

            // Check if availability column is mapped
            $isAvailabilityMapped = false;
            foreach ($columnMapping as $types) {
                if (is_array($types) && in_array('availability', $types, true)) {
                    $isAvailabilityMapped = true;
                    break;
                }
            }

            // Apply availability logic based on action
            if ($availabilityAction === 'set_from_price') {
                // Use value from price list if available, otherwise set to 0
                if (isset($match['availability']) && $match['availability'] !== null && $match['availability'] !== '') {
                    $stock = max(0, (int) $match['availability']);
                    $productUpdate['stock'] = $stock;
                } else {
                    // No availability in price list - set to 0
                    $productUpdate['stock'] = 0;
                }
            } elseif ($availabilityAction === 'set_1000') {
                // Always set to 1000
                $productUpdate['stock'] = 1000;
            }
            // If availabilityAction is 'dont_change', don't add stock to update

            $updateData[] = $productUpdate;

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

        // Handle zero stock for missing products
        $zeroStockForMissing = $config['filters']['zero_stock_for_missing'] ?? false;
        if ($zeroStockForMissing) {
            try {
                // Collect product IDs that were found in price list
                $matchedProductIds = [];
                foreach ($confirmedMatches as $match) {
                    if (isset($match['product_id']) && $match['product_id']) {
                        $matchedProductIds[] = $match['product_id'];
                    }
                }

                $zeroStockCount = $this->setZeroStockForMissingProducts(
                    $template,
                    $matchedProductIds,
                    $context
                );
                $stats['zero_stock_set'] = $zeroStockCount;
            } catch (\Exception $e) {
                $stats['zero_stock_error'] = $e->getMessage();
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

        // Auto-recalculate prices from custom fields using current exchange rates
        // This converts prices from custom fields (in various currencies) to product.price (in base currency)
        if ($stats['updated'] > 0) {
            try {
                $recalculateStats = $this->recalculatePricesFromCustomFields('all', null, $context);
                $stats['recalculated'] = $recalculateStats['updated'];
            } catch (\Exception $e) {
                // Log error but don't fail the whole operation
                $stats['recalculate_error'] = $e->getMessage();
            }
        }

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
            // Use categories association for filtering
            $criteria->addFilter(new EqualsAnyFilter('categories.id', $filters['categories']));
        }

        if (!empty($filters['manufacturers']) && is_array($filters['manufacturers'])) {
            $criteria->addFilter(new EqualsAnyFilter('manufacturerId', $filters['manufacturers']));
        }

        if (!empty($filters['equipment_types']) && is_array($filters['equipment_types'])) {
            // Equipment types are stored in custom fields
            $criteria->addFilter(new EqualsAnyFilter('customFields.equipment_type', $filters['equipment_types']));
        }

        if (!empty($filters['supplier'])) {
            // Supplier is stored in custom fields
            $criteria->addFilter(new EqualsFilter('customFields.supplier', $filters['supplier']));
        }

        // Load associations needed for matching
        $criteria->addAssociation('children');
        $criteria->addAssociation('categories');
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

            // Read from flat structure
            $purchasePrice = $customFields['purchase_price_value'] ?? null;
            $retailPrice = $customFields['retail_price_value'] ?? null;
            $listPrice = $customFields['list_price_value'] ?? null;

            if ($purchasePrice === null && $retailPrice === null && $listPrice === null) {
                $stats['skipped']++;
                continue;
            }

            // Reconstruct productPrices array for compatibility
            $productPrices = [
                'purchase_price_value' => $purchasePrice,
                'purchase_price_currency' => $customFields['purchase_price_currency'] ?? 'UAH',
                'retail_price_value' => $retailPrice,
                'retail_price_currency' => $customFields['retail_price_currency'] ?? 'UAH',
                'list_price_value' => $listPrice,
                'list_price_currency' => $customFields['list_price_currency'] ?? 'UAH',
            ];

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
        $currencies = $this->currencyRepository->search($currencyCriteria, $context);

        $currencyMap = [];
        foreach ($currencies as $currency) {
            /** @var \Shopware\Core\System\Currency\CurrencyEntity $currency */
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

        // Filter products that have any price custom fields (flat structure)
        $criteria->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter::CONNECTION_AND,
                [
                    new EqualsFilter('customFields.purchase_price_value', null),
                    new EqualsFilter('customFields.retail_price_value', null),
                    new EqualsFilter('customFields.list_price_value', null),
                ]
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

    /**
     * Set stock to 0 for products that match template filters but are NOT in current price list
     *
     * @param PriceTemplateEntity $template
     * @param array $matchedProductIds Product IDs that were found in current price list
     * @param Context $context
     * @return int Number of products updated
     */
    private function setZeroStockForMissingProducts(
        PriceTemplateEntity $template,
        array $matchedProductIds,
        Context $context
    ): int {
        $config = $template->getConfig();
        $filters = $config['filters'] ?? [];

        // Build criteria to find products that match template filters but are NOT in current price list
        $criteria = new Criteria();

        // Exclude products that were matched in current price list
        if (!empty($matchedProductIds)) {
            $criteria->addFilter(
                new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter(
                    \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter::CONNECTION_AND,
                    [new EqualsAnyFilter('id', $matchedProductIds)]
                )
            );
        }

        // Apply same filters as template (manufacturers, categories, equipment types, supplier)
        if (!empty($filters['manufacturers'])) {
            $criteria->addFilter(new EqualsAnyFilter('productManufacturerId', $filters['manufacturers']));
        }

        if (!empty($filters['categories'])) {
            $criteria->addFilter(new EqualsAnyFilter('categoryTree', $filters['categories']));
        }

        if (!empty($filters['equipment_types'])) {
            $criteria->addFilter(new EqualsAnyFilter('customFields.equipment_type', $filters['equipment_types']));
        }

        if (!empty($filters['supplier'])) {
            $criteria->addFilter(new EqualsFilter('customFields.supplier', $filters['supplier']));
        }

        // Limit to reasonable number to avoid performance issues
        $criteria->setLimit(5000);

        $products = $this->productRepository->search($criteria, $context);

        if ($products->count() === 0) {
            return 0;
        }

        // Prepare updates to set stock = 0
        $updateData = [];
        foreach ($products as $product) {
            $updateData[] = [
                'id' => $product->getId(),
                'stock' => 0,
            ];
        }

        // Update products in batches
        $batchSize = 100;
        $batches = array_chunk($updateData, $batchSize);
        $totalUpdated = 0;

        foreach ($batches as $batch) {
            try {
                $this->productRepository->update($batch, $context);
                $totalUpdated += count($batch);
            } catch (\Exception $e) {
                // Continue with next batch even if this one fails
                continue;
            }
        }

        return $totalUpdated;
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
