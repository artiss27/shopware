<?php declare(strict_types=1);

namespace Artiss\Supplier\Service;

use Shopware\Core\Content\Product\ProductCollection;

class ProductMatchingService
{
    /**
     * Главный метод сопоставления
     */
    public function matchProducts(
        array $priceListItems,
        ProductCollection $products
    ): array {
        $results = [];

        // Подготовка каталога
        $catalog = [];
        foreach ($products as $product) {
            $name = $product->getTranslation('name');
            if (!$name) {
                continue;
            }

            $normalized = $this->normalize($name);

            $catalog[] = [
                'id' => $product->getId(),
                'name' => $name,
                'productNumber' => $product->getProductNumber(),
                'tokens' => $this->tokenize($normalized),
            ];
        }

        // Обработка прайса
        foreach ($priceListItems as $priceItem) {
            if (empty($priceItem['name'])) {
                continue;
            }

            $match = $this->findBestMatch($catalog, $priceItem['name']);

            if ($match !== null) {
                $results[] = [
                    'price_item' => $priceItem,
                    'match' => $match,
                ];
            }
        }

        return $results;
    }

    /**
     * Поиск лучшего совпадения
     */
    private function findBestMatch(array $catalog, string $priceName): ?array
    {
        $normalizedPrice = $this->normalize($priceName);
        $priceString = str_replace(' ', '', $normalizedPrice);

        $level = 0;

        while (true) {
            $best = [];
            $maxMatches = 0;

            foreach ($catalog as $product) {
                $tokens = $this->getTokensForLevel($product['tokens'], $level);

                if (empty($tokens)) {
                    continue;
                }

                $matched = $this->countMatches($tokens, $priceString);

                if ($matched > $maxMatches) {
                    $maxMatches = $matched;
                    $best = [[
                                 'product' => $product,
                                 'matched' => $matched,
                                 'level' => $level,
                             ]];
                } elseif ($matched === $maxMatches && $matched > 0) {
                    $best[] = [
                        'product' => $product,
                        'matched' => $matched,
                        'level' => $level,
                    ];
                }
            }

            // один победитель
            if (count($best) === 1) {
                return $this->formatResult($best[0]);
            }

            // несколько — пробуем следующий уровень склейки
            if (count($best) > 1) {
                $canContinue = false;

                foreach ($best as $item) {
                    if (count($item['product']['tokens']) > $level + 1) {
                        $canContinue = true;
                        break;
                    }
                }

                if (!$canContinue) {
                    return $this->formatResult($best[0]);
                }

                $level++;
                continue;
            }

            // никого не нашли — по ТЗ не должно быть, но страховка
            return null;
        }
    }

    /**
     * Генерация токенов по уровню
     */
    private function getTokensForLevel(array $tokens, int $level): array
    {
        if ($level === 0) {
            return $tokens;
        }

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
     * Подсчёт совпадений
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
     * Токенизация
     */
    private function tokenize(string $value): array
    {
        return array_values(array_filter(explode(' ', $value)));
    }

    /**
     * Финальный формат результата
     */
    private function formatResult(array $item): array
    {
        return [
            'product_id' => $item['product']['id'],
            'product_name' => $item['product']['name'],
            'product_number' => $item['product']['productNumber'],
            'matched_tokens' => $item['matched'],
            'level' => $item['level'],
        ];
    }
}