<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Shopware\Core\Content\Media\MediaEntity;

/**
 * Excel parser for .xlsx and .xls files
 * Optimized for large files using chunk reading
 */
class ExcelParser extends AbstractPriceParser
{
    private const CHUNK_SIZE = 1000; // Process 1000 rows at a time for large files

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

        // Use read filter for memory efficiency with large files
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $result = [];
        $highestRow = $worksheet->getHighestRow();
        $endRow = $maxRows !== null ? min($startRow + $maxRows - 1, $highestRow) : $highestRow;

        // Determine max column index from mapping
        $maxColIndex = 0;
        foreach (array_keys($columnMapping) as $colLetter) {
            $colIndex = $this->columnToIndex($colLetter);
            $maxColIndex = max($maxColIndex, $colIndex);
        }
        $maxColIndex += 5; // Add buffer for safety

        for ($row = $startRow; $row <= $endRow; $row++) {
            // Read entire row
            $rowData = [];
            for ($col = 0; $col <= $maxColIndex; $col++) {
                $colLetter = $this->indexToColumn($col);
                $cellValue = $worksheet->getCellByColumnAndRow($col + 1, $row)->getValue();
                $rowData[$colLetter] = $cellValue;
            }

            // Map columns to data structure using new column_mapping
            $item = $this->mapRowData($rowData, $columnMapping);

            // Skip empty rows (no code or all prices empty)
            if (empty($item['code']) && empty($item['purchase_price']) && empty($item['retail_price']) && empty($item['list_price'])) {
                continue;
            }

            $result[] = $item;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

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

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $rows = [];
        $headers = [];

        // Read preview rows
        for ($row = 1; $row <= min($previewRows + 2, $worksheet->getHighestRow()); $row++) {
            $rowData = [];

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
                $columnLetter = $this->indexToColumn($col - 1);
                $rowData[$columnLetter] = $cellValue !== null ? (string) $cellValue : '';

                // First row as potential headers
                if ($row === 1) {
                    $headers[$columnLetter] = $cellValue !== null ? (string) $cellValue : $columnLetter;
                }
            }

            $rows[] = $rowData;
        }

        // Try to detect data start row (skip header rows)
        $suggestedStartRow = 2; // Default
        if (count($rows) > 1 && isset($rows[0])) {
            // Find first column that looks like a price
            $priceColumn = null;
            foreach ($rows[0] as $col => $value) {
                if (preg_match('/price|цена|ціна|cost|стоимость/ui', $value)) {
                    $priceColumn = $col;
                    break;
                }
            }

            if ($priceColumn !== null) {
                $suggestedStartRow = $this->detectDataStartRow(array_slice($rows, 1), $priceColumn) + 1;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'headers' => $headers,
            'rows' => array_slice($rows, 0, $previewRows),
            'suggested_start_row' => $suggestedStartRow,
        ];
    }

    public function getName(): string
    {
        return 'Excel Parser';
    }

    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'xls'];
    }

    /**
     * Get physical file path from media entity
     */
    private function getMediaFilePath(MediaEntity $media): string
    {
        // In Shopware, media files are stored in public/media/...
        // We need to construct the full path
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
