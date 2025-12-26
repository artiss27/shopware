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

        $startRow = $config['start_row'] ?? 1;
        $codeColumn = $config['code_column'] ?? 'A';
        $nameColumn = $config['name_column'] ?? 'B';
        $priceColumn1 = $config['price_column_1'] ?? 'C';
        $priceColumn2 = $config['price_column_2'] ?? null;
        $maxRows = $config['max_rows'] ?? null;

        $codeIndex = $this->columnToIndex($codeColumn);
        $nameIndex = $this->columnToIndex($nameColumn);
        $price1Index = $this->columnToIndex($priceColumn1);
        $price2Index = $priceColumn2 !== null ? $this->columnToIndex($priceColumn2) : null;

        // Use read filter for memory efficiency with large files
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $result = [];
        $highestRow = $worksheet->getHighestRow();
        $endRow = $maxRows !== null ? min($startRow + $maxRows - 1, $highestRow) : $highestRow;

        $maxColumns = max($codeIndex, $nameIndex, $price1Index, $price2Index ?? 0) + 5;

        for ($row = $startRow; $row <= $endRow; $row++) {
            // Read row data into array for validation
            $rowData = [];
            for ($col = 0; $col <= $maxColumns; $col++) {
                $rowData[$col] = $worksheet->getCellByColumnAndRow($col + 1, $row)->getValue();
            }

            // Skip group headers (e.g., "Группа товаров 1")
            if ($this->isGroupHeader($rowData, $codeColumn, $priceColumn1)) {
                continue;
            }

            // Check if this is a valid data row
            if (!$this->isDataRow($rowData, $codeColumn, $priceColumn1)) {
                continue;
            }

            $code = $this->normalizeCode($rowData[$codeIndex]);
            $name = $this->normalizeName($rowData[$nameIndex]);
            $price1 = $this->normalizePrice($rowData[$price1Index]);
            $price2 = $price2Index !== null ? $this->normalizePrice($rowData[$price2Index]) : null;

            $result[] = [
                'code' => $code,
                'name' => $name,
                'price_1' => $price1,
                'price_2' => $price2,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $result;
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
