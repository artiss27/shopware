<?php declare(strict_types=1);

namespace Artiss\Supplier\Service;

use Shopware\Core\Content\Product\ProductCollection;

class ProductMatchingService
{
    public function matchProducts(
        array $priceListItems,
        ProductCollection $products
    ): array {
        $results = [];

        $priceList = [];
        foreach ($priceListItems as $priceItem) {
            if (empty($priceItem['name'])) {
                continue;
            }

            $normalized = $this->normalize($priceItem['name']);

            $priceList[] = [
                'item' => $priceItem,
                'priceString' => str_replace(' ', '', $normalized),
            ];
        }

        foreach ($products as $product) {
            $name = $product->getTranslation('name');
            if (!$name) {
                continue;
            }

            $normalized = $this->normalize($name);
            $tokens = $this->tokenize($normalized);

            $match = $this->findBestMatchInPriceList($tokens, $priceList);

            if ($match !== null) {
                $results[] = [
                    'price_item' => $match['price_item'],
                    'match' => [
                        'product_id' => $product->getId(),
                        'product_name' => $name,
                        'product_number' => $product->getProductNumber(),
                        'matched_tokens' => $match['matched_tokens'],
                        'original_tokens_count' => count($tokens),
                        'level' => $match['level'],
                    ],
                ];
            }
        }

        return $results;
    }

    private function findBestMatchInPriceList(array $catalogTokens, array $priceList): ?array
    {
        $originalTokensCount = count($catalogTokens);
        $maxMatches = 0;
        $candidates = [];

        foreach ($priceList as $priceItem) {
            $matched = $this->countMatches($catalogTokens, $priceItem['priceString']);

            if ($matched > $maxMatches) {
                $maxMatches = $matched;
                $candidates = [$priceItem];
            } elseif ($matched === $maxMatches && $matched > 0) {
                $candidates[] = $priceItem;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        if (count($candidates) === 1) {
            return [
                'price_item' => $candidates[0]['item'],
                'matched_tokens' => $maxMatches,
                'level' => 0,
            ];
        }

        $level = 1;

        while (count($candidates) > 1 && $level < $originalTokensCount) {
            $compositeTokens = $this->generateCompositeTokens($catalogTokens, $level);

            if (empty($compositeTokens)) {
                break;
            }

            $maxMatchesLevel = 0;
            $newCandidates = [];

            foreach ($candidates as $candidate) {
                $matched = $this->countMatches($compositeTokens, $candidate['priceString']);

                if ($matched > $maxMatchesLevel) {
                    $maxMatchesLevel = $matched;
                    $newCandidates = [$candidate];
                } elseif ($matched === $maxMatchesLevel && $matched > 0) {
                    $newCandidates[] = $candidate;
                }
            }

            if (empty($newCandidates)) {
                break;
            }

            $candidates = $newCandidates;
            $level++;
        }

        return [
            'price_item' => $candidates[0]['item'],
            'matched_tokens' => $maxMatches,
            'level' => $level - 1,
        ];
    }

    private function generateCompositeTokens(array $tokens, int $level): array
    {
        $size = $level + 1;

        if ($size > count($tokens)) {
            return [];
        }

        $result = [];
        for ($i = 0; $i <= count($tokens) - $size; $i++) {
            $result[] = implode('', array_slice($tokens, $i, $size));
        }

        return $result;
    }

    /**
     * Подсчёт совпадений токенов в строке прайса
     */
    private function countMatches(array $tokens, string $haystack): int
    {
        $count = 0;

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Нормализация строки
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-zа-я0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Токенизация (разбиение по пробелу)
     */
    private function tokenize(string $value): array
    {
        return array_values(array_filter(explode(' ', $value)));
    }
}
