<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Matcher;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;

/**
 * Fuzzy name matcher - matches by product name similarity
 * Priority: Low (runs after exact matchers)
 *
 * Uses similar_text() for fuzzy matching
 * Confidence levels based on similarity percentage
 */
class FuzzyNameMatcher implements ProductMatcherInterface
{
    private const HIGH_CONFIDENCE_THRESHOLD = 90;
    private const MEDIUM_CONFIDENCE_THRESHOLD = 80;
    private const MIN_MATCH_THRESHOLD = 70;

    public function match(
        array $priceItem,
        ProductCollection $products,
        array $matchedProductsMap,
        string $supplierId
    ): ?array {
        $priceName = $priceItem['name'];

        if ($priceName === null || trim($priceName) === '') {
            return null;
        }

        $normalizedPriceName = $this->normalizeName($priceName);
        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($products as $product) {
            $similarity = $this->calculateSimilarity($normalizedPriceName, $product);

            if ($similarity > $bestSimilarity && $similarity >= self::MIN_MATCH_THRESHOLD) {
                $bestSimilarity = $similarity;
                $bestMatch = $product;
            }

            // Also check variants
            if ($product->getChildren() !== null) {
                foreach ($product->getChildren() as $variant) {
                    $variantSimilarity = $this->calculateSimilarity($normalizedPriceName, $variant);

                    if ($variantSimilarity > $bestSimilarity && $variantSimilarity >= self::MIN_MATCH_THRESHOLD) {
                        $bestSimilarity = $variantSimilarity;
                        $bestMatch = $variant;
                    }
                }
            }
        }

        if ($bestMatch === null) {
            return null;
        }

        $confidence = $this->getConfidenceLevel($bestSimilarity);

        return [
            'product_id' => $bestMatch->getId(),
            'confidence' => $confidence,
            'method' => 'fuzzy_name',
            'similarity' => $bestSimilarity,
        ];
    }

    public function getPriority(): int
    {
        return 50; // Medium priority - after exact matchers
    }

    public function getName(): string
    {
        return 'Fuzzy Name Matcher';
    }

    /**
     * Calculate name similarity between price item and product
     */
    private function calculateSimilarity(string $normalizedPriceName, ProductEntity $product): float
    {
        $productName = $product->getTranslated()['name'] ?? $product->getName() ?? '';
        $normalizedProductName = $this->normalizeName($productName);

        if ($normalizedProductName === '') {
            return 0;
        }

        similar_text($normalizedPriceName, $normalizedProductName, $percent);

        return $percent;
    }

    /**
     * Normalize product name for comparison
     */
    private function normalizeName(string $name): string
    {
        // Convert to lowercase
        $normalized = mb_strtolower($name);

        // Remove special characters but keep spaces and alphanumeric
        $normalized = preg_replace('/[^a-zа-яіїєґ0-9\s]/u', '', $normalized);

        // Remove extra spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Get confidence level based on similarity percentage
     */
    private function getConfidenceLevel(float $similarity): string
    {
        if ($similarity >= self::HIGH_CONFIDENCE_THRESHOLD) {
            return 'high';
        }

        if ($similarity >= self::MEDIUM_CONFIDENCE_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }
}
