<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Matcher;

use Shopware\Core\Content\Product\ProductCollection;

/**
 * Chain of Responsibility pattern for product matching
 * Runs matchers in priority order until a match is found
 */
class MatcherChain
{
    /**
     * @var ProductMatcherInterface[]
     */
    private array $matchers = [];

    public function __construct(iterable $matchers)
    {
        foreach ($matchers as $matcher) {
            $this->addMatcher($matcher);
        }

        // Sort matchers by priority (highest first)
        usort($this->matchers, function (ProductMatcherInterface $a, ProductMatcherInterface $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    /**
     * Add matcher to chain
     */
    public function addMatcher(ProductMatcherInterface $matcher): void
    {
        $this->matchers[] = $matcher;
    }

    /**
     * Match single price list item
     *
     * @param array $priceItem Price list item
     * @param ProductCollection $products Available products to match against
     * @param array $matchedProductsMap Existing product_id => supplier_code mapping
     * @param string $supplierId Supplier ID
     *
     * @return array|null Match result or null if no match found
     */
    public function matchOne(
        array $priceItem,
        ProductCollection $products,
        array $matchedProductsMap,
        string $supplierId
    ): ?array {
        foreach ($this->matchers as $matcher) {
            $result = $matcher->match($priceItem, $products, $matchedProductsMap, $supplierId);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Match all price list items
     *
     * @param array $priceData Array of price list items
     * @param ProductCollection $products Available products
     * @param array $matchedProductsMap Existing mapping
     * @param string $supplierId Supplier ID
     *
     * @return array Array of match results with keys:
     *   - matched: array - Successfully matched items
     *   - unmatched: array - Items without match
     *   - stats: array - Statistics
     */
    public function matchAll(
        array $priceData,
        ProductCollection $products,
        array $matchedProductsMap,
        string $supplierId
    ): array {
        $matched = [];
        $unmatched = [];
        $stats = [
            'total' => count($priceData),
            'matched' => 0,
            'unmatched' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'by_method' => [],
        ];

        foreach ($priceData as $index => $priceItem) {
            $matchResult = $this->matchOne($priceItem, $products, $matchedProductsMap, $supplierId);

            if ($matchResult !== null) {
                $matched[] = array_merge($priceItem, [
                    'matched' => $matchResult,
                ]);

                $stats['matched']++;
                $stats[$matchResult['confidence'] . '_confidence']++;

                $method = $matchResult['method'];
                if (!isset($stats['by_method'][$method])) {
                    $stats['by_method'][$method] = 0;
                }
                $stats['by_method'][$method]++;
            } else {
                $unmatched[] = $priceItem;
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
     * Get all registered matchers (sorted by priority)
     *
     * @return ProductMatcherInterface[]
     */
    public function getMatchers(): array
    {
        return $this->matchers;
    }
}
