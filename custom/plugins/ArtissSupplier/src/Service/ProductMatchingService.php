<?php declare(strict_types=1);

namespace Artiss\Supplier\Service;

use Shopware\Core\Content\Product\ProductCollection;

class ProductMatchingService
{
    /**
     * Главный метод сопоставления
     * Для товара ИЗ КАТАЛОГА ищем лучший в прайсе
     */
    public function matchProducts(
        array $priceListItems,
        ProductCollection $products
    ): array {
        $results = [];

        // Подготовка прайса (нормализация + слитная строка БЕЗ пробелов)
        $priceList = [];
        foreach ($priceListItems as $priceItem) {
            if (empty($priceItem['name'])) {
                continue;
            }

            $normalized = $this->normalize($priceItem['name']);

            $priceList[] = [
                'item' => $priceItem,
                'priceString' => str_replace(' ', '', $normalized), // убираем ВСЕ пробелы
            ];
        }

        // Для каждого товара ИЗ КАТАЛОГА ищем лучший в прайсе
        foreach ($products as $product) {
            $name = $product->getTranslation('name');
            if (!$name) {
                continue;
            }

            $normalized = $this->normalize($name);
            $tokens = $this->tokenize($normalized); // токены по пробелу

            // Ищем лучшее совпадение ПО ВСЕМУ ПРАЙСУ
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

    /**
     * Поиск лучшего совпадения в прайсе для товара из каталога
     */
    private function findBestMatchInPriceList(array $catalogTokens, array $priceList): ?array
    {
        $originalTokensCount = count($catalogTokens);

        // ШАГ 1: Первый проход с исходными токенами
        // Проходим по ВСЕМУ прайсу и ищем максимум
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

        // Если нет кандидатов
        if (empty($candidates)) {
            return null;
        }

        // Если один кандидат - возвращаем
        if (count($candidates) === 1) {
            return [
                'price_item' => $candidates[0]['item'],
                'matched_tokens' => $maxMatches, // из первого прохода!
                'level' => 0,
            ];
        }

        // ШАГ 2: Если несколько кандидатов - уточняем составными токенами
        $level = 1;

        while (count($candidates) > 1 && $level < $originalTokensCount) {
            // Генерируем составные токены путем объединения соседних
            $compositeTokens = $this->generateCompositeTokens($catalogTokens, $level);

            if (empty($compositeTokens)) {
                break;
            }

            $maxMatchesLevel = 0;
            $newCandidates = [];

            // Проверяем ТОЛЬКО среди кандидатов
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

        // Возвращаем первого (или единственного)
        return [
            'price_item' => $candidates[0]['item'],
            'matched_tokens' => $maxMatches, // из ПЕРВОГО прохода!
            'level' => $level - 1,
        ];
    }

    /**
     * Генерация составных токенов путем объединения соседних
     * level = 1: объединяем по 2 соседних
     * level = 2: объединяем по 3 соседних
     * и т.д.
     */
    private function generateCompositeTokens(array $tokens, int $level): array
    {
        $size = $level + 1; // level 1 = 2 токена, level 2 = 3 токена

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
