<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use Shopware\Core\Content\Media\MediaEntity;

/**
 * CSV parser for .csv files
 * Supports various delimiters and encodings
 */
class CsvParser extends AbstractPriceParser
{
    private const DEFAULT_DELIMITER = ',';
    private const FALLBACK_DELIMITERS = [';', "\t", '|'];

    public function supports(MediaEntity $media): bool
    {
        $extension = $this->getFileExtension($media);
        return in_array($extension, $this->getSupportedExtensions(), true);
    }

    public function parse(MediaEntity $media, array $config): array
    {
        $filePath = $this->getMediaFilePath($media);
        $this->validateFile($filePath);

        $startRow = $config['start_row'] ?? 2;
        $columnMapping = $config['column_mapping'] ?? [];
        $maxRows = $config['max_rows'] ?? null;
        $delimiter = $config['delimiter'] ?? null; // Auto-detect if not provided

        // Auto-detect delimiter if not provided
        if ($delimiter === null) {
            $delimiter = $this->detectDelimiter($filePath);
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $result = [];
        $currentRow = 0;
        $parsedRows = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $currentRow++;

            // Skip rows before start row
            if ($currentRow < $startRow) {
                continue;
            }

            // Check max rows limit
            if ($maxRows !== null && $parsedRows >= $maxRows) {
                break;
            }

            // Convert array data to associative array with column letters
            $rowData = [];
            foreach ($data as $colIndex => $value) {
                $colLetter = $this->indexToColumn($colIndex);
                $rowData[$colLetter] = $value;
            }

            // Map columns to data structure using new column_mapping
            $item = $this->mapRowData($rowData, $columnMapping);

            // Skip empty rows
            if (empty($item['code']) && empty($item['purchase_price']) && empty($item['retail_price']) && empty($item['list_price'])) {
                continue;
            }

            $result[] = $item;
            $parsedRows++;
        }

        fclose($handle);

        return $result;
    }

    /**
     * Map row data using column mapping configuration
     */
    private function mapRowData(array $rowData, array $columnMapping): array
    {
        $item = [
            'code' => null,
            'name' => null,
            'purchase_price' => null,
            'retail_price' => null,
            'list_price' => null,
            'availability' => null,
        ];

        foreach ($columnMapping as $colLetter => $types) {
            if (!is_array($types)) {
                $types = [$types];
            }

            $cellValue = $rowData[$colLetter] ?? null;

            foreach ($types as $type) {
                switch ($type) {
                    case 'product_code':
                        $item['code'] = $this->normalizeCode($cellValue);
                        break;
                    case 'product_name':
                        $item['name'] = $this->normalizeName($cellValue);
                        break;
                    case 'purchase_price':
                        $item['purchase_price'] = $this->normalizePrice($cellValue);
                        break;
                    case 'retail_price':
                        $item['retail_price'] = $this->normalizePrice($cellValue);
                        break;
                    case 'list_price':
                        $item['list_price'] = $this->normalizePrice($cellValue);
                        break;
                    case 'availability':
                        $item['availability'] = $this->normalizeAvailability($cellValue);
                        break;
                    // 'ignore' type - do nothing
                }
            }
        }

        return $item;
    }

    /**
     * Normalize availability value
     */
    private function normalizeAvailability($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function preview(MediaEntity $media, int $previewRows = 5): array
    {
        $filePath = $this->getMediaFilePath($media);
        $this->validateFile($filePath);

        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $rows = [];
        $headers = [];
        $rowCount = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false && $rowCount < $previewRows + 2) {
            $rowData = [];

            foreach ($data as $colIndex => $value) {
                $columnLetter = $this->indexToColumn($colIndex);
                $rowData[$columnLetter] = $value ?? '';

                // First row as potential headers
                if ($rowCount === 0) {
                    $headers[$columnLetter] = $value ?? $columnLetter;
                }
            }

            $rows[] = $rowData;
            $rowCount++;
        }

        fclose($handle);

        // Try to detect data start row
        $suggestedStartRow = 2;
        if (count($rows) > 1) {
            foreach ($rows[0] as $col => $value) {
                if (preg_match('/price|цена|ціна|cost|стоимость/ui', $value)) {
                    $suggestedStartRow = $this->detectDataStartRow(array_slice($rows, 1), $col) + 1;
                    break;
                }
            }
        }

        return [
            'headers' => $headers,
            'rows' => array_slice($rows, 0, $previewRows),
            'suggested_start_row' => $suggestedStartRow,
            'detected_delimiter' => $delimiter,
        ];
    }

    public function getName(): string
    {
        return 'CSV Parser';
    }

    public function getSupportedExtensions(): array
    {
        return ['csv', 'txt'];
    }

    /**
     * Auto-detect CSV delimiter by analyzing first few lines
     */
    private function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return self::DEFAULT_DELIMITER;
        }

        $sampleLines = [];
        for ($i = 0; $i < 3; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sampleLines[] = $line;
        }

        fclose($handle);

        if (empty($sampleLines)) {
            return self::DEFAULT_DELIMITER;
        }

        $sample = implode('', $sampleLines);

        // Count occurrences of each delimiter
        $delimiterCounts = [self::DEFAULT_DELIMITER => 0];
        foreach (self::FALLBACK_DELIMITERS as $delimiter) {
            $delimiterCounts[$delimiter] = 0;
        }

        foreach ($delimiterCounts as $delimiter => $count) {
            $delimiterCounts[$delimiter] = substr_count($sample, $delimiter);
        }

        // Return delimiter with highest count
        arsort($delimiterCounts);
        $bestDelimiter = array_key_first($delimiterCounts);

        return $bestDelimiter !== null ? $bestDelimiter : self::DEFAULT_DELIMITER;
    }

    /**
     * Get physical file path from media entity
     */
    private function getMediaFilePath(MediaEntity $media): string
    {
        $mediaPath = $media->getPath();

        if ($mediaPath === null) {
            throw new \RuntimeException('Media has no path');
        }

        // Go up 6 levels from src/Service/Parser/ to get to project root
        // Then add /public to get to the public directory
        $basePath = dirname(__DIR__, 6) . '/public';
        $fullPath = $basePath . '/' . $mediaPath;

        return $fullPath;
    }
}
