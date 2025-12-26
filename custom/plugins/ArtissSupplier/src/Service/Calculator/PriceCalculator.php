<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Calculator;

/**
 * Price calculator with modifiers support
 *
 * Supports three modes:
 * - single_purchase: One column with purchase price (retail = purchase + modifier)
 * - single_retail: One column with retail price (purchase = retail + modifier)
 * - dual: Two columns with both prices
 */
class PriceCalculator
{
    /**
     * Calculate final prices based on price rules
     *
     * @param array $priceData Price data from parser: [code, name, price_1, price_2]
     * @param array $priceRules Price rules configuration:
     *   - mode: string - 'single_purchase', 'single_retail', 'dual'
     *   - price_1_is: string - 'purchase' or 'retail'
     *   - price_2_is: string - 'purchase' or 'retail' (for dual mode)
     *   - purchase_modifier: array - Modifier for purchase price calculation
     *   - retail_modifier: array - Modifier for retail price calculation
     *
     * @return array Calculated prices: [purchase_price, retail_price]
     */
    public function calculate(array $priceData, array $priceRules): array
    {
        $mode = $priceRules['mode'] ?? 'dual';
        $price1 = $priceData['price_1'] ?? null;
        $price2 = $priceData['price_2'] ?? null;

        switch ($mode) {
            case 'single_purchase':
                return $this->calculateFromPurchase($price1, $priceRules);

            case 'single_retail':
                return $this->calculateFromRetail($price1, $priceRules);

            case 'dual':
                return $this->calculateDual($price1, $price2, $priceRules);

            default:
                throw new \InvalidArgumentException("Unknown price mode: {$mode}");
        }
    }

    /**
     * Calculate batch of prices
     *
     * @param array $priceDataArray Array of price data items
     * @param array $priceRules Price rules
     *
     * @return array Array of calculated prices
     */
    public function calculateBatch(array $priceDataArray, array $priceRules): array
    {
        $results = [];

        foreach ($priceDataArray as $priceData) {
            $calculated = $this->calculate($priceData, $priceRules);
            $results[] = array_merge($priceData, [
                'calculated_purchase_price' => $calculated['purchase_price'],
                'calculated_retail_price' => $calculated['retail_price'],
            ]);
        }

        return $results;
    }

    /**
     * Calculate prices when only purchase price is available
     */
    private function calculateFromPurchase(?float $purchasePrice, array $priceRules): array
    {
        if ($purchasePrice === null) {
            return ['purchase_price' => null, 'retail_price' => null];
        }

        $retailModifier = $priceRules['retail_modifier'] ?? ['type' => 'percentage', 'value' => 30];
        $retailPrice = $this->applyModifier($purchasePrice, $retailModifier);

        return [
            'purchase_price' => $purchasePrice,
            'retail_price' => $retailPrice,
        ];
    }

    /**
     * Calculate prices when only retail price is available
     */
    private function calculateFromRetail(?float $retailPrice, array $priceRules): array
    {
        if ($retailPrice === null) {
            return ['purchase_price' => null, 'retail_price' => null];
        }

        $purchaseModifier = $priceRules['purchase_modifier'] ?? ['type' => 'percentage', 'value' => -20];
        $purchasePrice = $this->applyModifier($retailPrice, $purchaseModifier);

        return [
            'purchase_price' => $purchasePrice,
            'retail_price' => $retailPrice,
        ];
    }

    /**
     * Calculate prices when both prices are available
     */
    private function calculateDual(?float $price1, ?float $price2, array $priceRules): array
    {
        $price1Is = $priceRules['price_1_is'] ?? 'purchase';
        $price2Is = $priceRules['price_2_is'] ?? 'retail';

        $purchasePrice = null;
        $retailPrice = null;

        // Assign prices based on configuration
        if ($price1Is === 'purchase') {
            $purchasePrice = $price1;
        } else {
            $retailPrice = $price1;
        }

        if ($price2Is === 'purchase') {
            $purchasePrice = $price2;
        } else {
            $retailPrice = $price2;
        }

        // Calculate missing price if one is available
        if ($purchasePrice !== null && $retailPrice === null) {
            $retailModifier = $priceRules['retail_modifier'] ?? ['type' => 'percentage', 'value' => 30];
            $retailPrice = $this->applyModifier($purchasePrice, $retailModifier);
        } elseif ($retailPrice !== null && $purchasePrice === null) {
            $purchaseModifier = $priceRules['purchase_modifier'] ?? ['type' => 'percentage', 'value' => -20];
            $purchasePrice = $this->applyModifier($retailPrice, $purchaseModifier);
        }

        return [
            'purchase_price' => $purchasePrice,
            'retail_price' => $retailPrice,
        ];
    }

    /**
     * Apply price modifier
     *
     * @param float $basePrice Base price
     * @param array $modifier Modifier config: ['type' => 'percentage'|'fixed', 'value' => number]
     *
     * @return float|null Modified price
     */
    private function applyModifier(float $basePrice, array $modifier): ?float
    {
        $type = $modifier['type'] ?? 'percentage';
        $value = $modifier['value'] ?? 0;

        if ($type === 'percentage') {
            // Percentage modifier: value in %
            // +30 = increase by 30%, -20 = decrease by 20%
            return $basePrice * (1 + ($value / 100));
        }

        if ($type === 'fixed') {
            // Fixed amount modifier
            return $basePrice + $value;
        }

        if ($type === 'none') {
            return $basePrice;
        }

        throw new \InvalidArgumentException("Unknown modifier type: {$type}");
    }

    /**
     * Round price to 2 decimal places
     */
    public function roundPrice(?float $price): ?float
    {
        if ($price === null) {
            return null;
        }

        return round($price, 2);
    }
}
