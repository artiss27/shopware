<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Matcher;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;

/**
 * Exact code matcher - matches by supplier code
 * Priority: Highest (runs first)
 *
 * Matching strategy:
 * 1. Check matched_products mapping (product_id => supplier_code from template)
 * 2. Check product custom field "supplier_code"
 * 3. Check parent product for variants
 */
class ExactCodeMatcher implements ProductMatcherInterface
{
    public function match(
        array $priceItem,
        ProductCollection $products,
        array $matchedProductsMap,
        string $supplierId
    ): ?array {
        $supplierCode = $priceItem['code'];

        if ($supplierCode === null) {
            return null;
        }

        // Strategy 1: Check matched_products mapping (reverse lookup)
        $productIdByMapping = array_search($supplierCode, $matchedProductsMap, true);
        if ($productIdByMapping !== false) {
            // Verify product still exists in collection
            $product = $products->get($productIdByMapping);
            if ($product !== null) {
                return [
                    'product_id' => $productIdByMapping,
                    'confidence' => 'high',
                    'method' => 'exact_mapping',
                ];
            }
        }

        // Strategy 2: Search by custom field "supplier_code" in all products
        foreach ($products as $product) {
            $productSupplierCode = $this->getSupplierCode($product);

            if ($productSupplierCode !== null && $productSupplierCode === $supplierCode) {
                return [
                    'product_id' => $product->getId(),
                    'confidence' => 'high',
                    'method' => 'exact_custom_field',
                ];
            }

            // Strategy 3: Check variants if product has them
            if ($product->getChildren() !== null) {
                foreach ($product->getChildren() as $variant) {
                    $variantSupplierCode = $this->getSupplierCode($variant);

                    if ($variantSupplierCode !== null && $variantSupplierCode === $supplierCode) {
                        return [
                            'product_id' => $variant->getId(),
                            'confidence' => 'high',
                            'method' => 'exact_variant_code',
                        ];
                    }
                }
            }
        }

        return null;
    }

    public function getPriority(): int
    {
        return 100; // Highest priority - exact matches first
    }

    public function getName(): string
    {
        return 'Exact Code Matcher';
    }

    /**
     * Get supplier code from product custom fields
     */
    private function getSupplierCode(ProductEntity $product): ?string
    {
        $customFields = $product->getCustomFields();

        if ($customFields === null) {
            return null;
        }

        // Check both possible field names
        $code = $customFields['supplier_code'] ?? $customFields['art_supplier_code'] ?? null;

        return $code !== null ? strtoupper(trim($code)) : null;
    }
}
