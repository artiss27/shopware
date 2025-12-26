<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use Shopware\Core\Content\Media\MediaEntity;

/**
 * Base class for price parsers with common utility methods
 */
abstract class AbstractPriceParser implements PriceParserInterface
{
    /**
     * Convert column letter to index (A=0, B=1, Z=25, AA=26, etc.)
     */
    protected function columnToIndex(string|int $column): int
    {
        if (is_numeric($column)) {
            return (int) $column;
        }

        $column = strtoupper($column);
        $length = strlen($column);
        $index = 0;

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - ord('A') + 1);
        }

        return $index - 1; // 0-indexed
    }

    /**
     * Convert column index to letter (0=A, 1=B, 25=Z, 26=AA, etc.)
     */
    protected function indexToColumn(int $index): string
    {
        $column = '';
        $index++; // 1-indexed for calculation

        while ($index > 0) {
            $index--;
            $column = chr(($index % 26) + ord('A')) . $column;
            $index = (int) ($index / 26);
        }

        return $column;
    }

    /**
     * Normalize price value (remove currency symbols, parse float)
     */
    protected function normalizePrice(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Remove common currency symbols and spaces
        $value = preg_replace('/[₴$€£\s]/u', '', $value);

        // Replace comma with dot for decimal separator
        $value = str_replace(',', '.', $value);

        // Remove all non-numeric characters except dot and minus
        $value = preg_replace('/[^\d.-]/', '', $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Normalize product code (trim, uppercase)
     */
    protected function normalizeCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }

    /**
     * Normalize product name (trim)
     */
    protected function normalizeName(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Auto-detect data start row by finding first row with numeric price
     *
     * @param array $rows Preview rows
     * @param string|int $priceColumn Price column to check
     * @return int Suggested start row (1-indexed)
     */
    protected function detectDataStartRow(array $rows, string|int $priceColumn): int
    {
        $priceIndex = $this->columnToIndex($priceColumn);

        foreach ($rows as $rowIndex => $row) {
            if (!isset($row[$priceIndex])) {
                continue;
            }

            $value = $row[$priceIndex];
            $normalized = $this->normalizePrice($value);

            if ($normalized !== null && $normalized > 0) {
                // Found first row with valid price - this is likely data start
                return $rowIndex + 1; // 1-indexed
            }
        }

        // Default to row 2 if no numeric price found
        return 2;
    }

    /**
     * Check if row is a data row (has valid price or product code)
     * Used to skip group headers and empty rows in the middle of price lists
     *
     * @param array $row Row data indexed by column letter
     * @param string|int $codeColumn Code column identifier
     * @param string|int $priceColumn Price column identifier
     * @return bool True if row contains data
     */
    protected function isDataRow(array $row, string|int $codeColumn, string|int $priceColumn): bool
    {
        $codeIndex = $this->columnToIndex($codeColumn);
        $priceIndex = $this->columnToIndex($priceColumn);

        $code = $this->normalizeCode($row[$codeIndex] ?? null);
        $price = $this->normalizePrice($row[$priceIndex] ?? null);

        // Valid data row must have either code or price
        // This allows rows with code but no price (for manual price entry)
        // and rows with price but no code (though less common)
        if ($code !== null || $price !== null) {
            return true;
        }

        return false;
    }

    /**
     * Check if row is a group header (text in first column, empty in data columns)
     * Common patterns: "Группа товаров 1", "Category: Tools", etc.
     *
     * @param array $row Row data
     * @param string|int $codeColumn Code column
     * @param string|int $priceColumn Price column
     * @return bool True if row is a group header
     */
    protected function isGroupHeader(array $row, string|int $codeColumn, string|int $priceColumn): bool
    {
        $codeIndex = $this->columnToIndex($codeColumn);
        $priceIndex = $this->columnToIndex($priceColumn);

        // Check if first column has text but code/price columns are empty
        $firstCell = trim($row[0] ?? '');
        $codeCell = trim($row[$codeIndex] ?? '');
        $priceCell = trim($row[$priceIndex] ?? '');

        if ($firstCell !== '' && $codeCell === '' && $priceCell === '') {
            // Additional check: group headers often contain keywords
            $groupKeywords = [
                'группа', 'group', 'категория', 'category',
                'раздел', 'section', 'тип', 'type'
            ];

            $lowerFirst = mb_strtolower($firstCell);
            foreach ($groupKeywords as $keyword) {
                if (str_contains($lowerFirst, $keyword)) {
                    return true;
                }
            }

            // If first cell is longer than typical product code (>30 chars), likely a header
            if (mb_strlen($firstCell) > 30) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file exists and is readable
     *
     * @throws \RuntimeException
     */
    protected function validateFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File is not readable: {$filePath}");
        }
    }

    /**
     * Get file extension from media entity
     */
    protected function getFileExtension(MediaEntity $media): string
    {
        $extension = $media->getFileExtension();

        if ($extension === null) {
            throw new \RuntimeException('Media file has no extension');
        }

        return strtolower($extension);
    }
}
