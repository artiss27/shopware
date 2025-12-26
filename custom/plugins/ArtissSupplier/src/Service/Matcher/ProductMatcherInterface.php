<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Matcher;

use Shopware\Core\Content\Product\ProductCollection;

/**
 * Interface for product matching strategies
 * Matches price list items to products in the system
 */
interface ProductMatcherInterface
{
    /**
     * Match a single price list item to a product
     *
     * @param array $priceItem Price list item with keys: code, name, price_1, price_2
     * @param ProductCollection $products Filtered product collection to search in
     * @param array $matchedProductsMap Existing product_id => supplier_code mapping
     * @param string $supplierId Supplier ID for context
     *
     * @return array|null Match result with keys:
     *   - product_id: string - Matched product ID
     *   - confidence: string - Match confidence: 'high', 'medium', 'low'
     *   - method: string - Match method used (e.g., 'exact_code', 'fuzzy_name')
     *   Returns null if no match found
     */
    public function match(
        array $priceItem,
        ProductCollection $products,
        array $matchedProductsMap,
        string $supplierId
    ): ?array;

    /**
     * Get matcher priority (higher = runs first)
     * Exact matchers should have higher priority than fuzzy matchers
     *
     * @return int Priority (0-100)
     */
    public function getPriority(): int;

    /**
     * Get matcher name for display/debugging
     *
     * @return string
     */
    public function getName(): string;
}
