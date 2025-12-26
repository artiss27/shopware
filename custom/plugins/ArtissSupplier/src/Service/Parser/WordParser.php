<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Row;
use Shopware\Core\Content\Media\MediaEntity;

class WordParser extends AbstractPriceParser
{
    public function supports(MediaEntity $media): bool
    {
        return in_array(strtolower($media->getFileExtension()), $this->getSupportedExtensions());
    }

    public function getSupportedExtensions(): array
    {
        return ['docx'];
    }

    public function getName(): string
    {
        return 'Word Parser';
    }

    public function preview(MediaEntity $media, int $previewRows = 5): array
    {
        $filePath = $this->getMediaFilePath($media);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        try {
            $phpWord = IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to load Word document: " . $e->getMessage());
        }

        $allRows = [];
        $headers = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Table) {
                    $rows = $element->getRows();

                    foreach ($rows as $rowIndex => $row) {
                        if (count($allRows) >= $previewRows + 2) {
                            break 2;
                        }

                        $rowData = $this->extractRowData($row);
                        $formattedRow = [];

                        foreach ($rowData as $colIndex => $cell) {
                            $columnLetter = $this->indexToColumn($colIndex);
                            $formattedRow[$columnLetter] = $cell;

                            if ($rowIndex === 0) {
                                $headers[$columnLetter] = $cell ?: $columnLetter;
                            }
                        }

                        $allRows[] = $formattedRow;
                    }
                }
            }
        }

        return [
            'headers' => $headers,
            'rows' => array_slice($allRows, 0, $previewRows),
            'suggested_start_row' => 2,
        ];
    }

    public function parse(MediaEntity $media, array $config): array
    {
        $filePath = $this->getMediaFilePath($media);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        try {
            $phpWord = IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to load Word document: " . $e->getMessage());
        }

        $result = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Table) {
                    $tableData = $this->parseTable($element, $config);
                    $result = array_merge($result, $tableData);
                }
            }
        }

        return $result;
    }

    private function parseTable(Table $table, array $config): array
    {
        $data = [];
        $rows = $table->getRows();
        $startRow = $config['mapping']['start_row'] ?? 1;
        $codeColumn = $config['mapping']['code_column'] ?? 'A';
        $nameColumn = $config['mapping']['name_column'] ?? 'B';
        $priceColumn1 = $config['mapping']['price_column_1'] ?? 'C';
        $priceColumn2 = $config['mapping']['price_column_2'] ?? 'D';

        $codeIndex = $this->columnToIndex($codeColumn);
        $nameIndex = $this->columnToIndex($nameColumn);
        $priceIndex1 = $this->columnToIndex($priceColumn1);
        $priceIndex2 = $this->columnToIndex($priceColumn2);

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex < $startRow - 1) {
                continue;
            }

            $rowData = $this->extractRowData($row);

            if ($this->isGroupHeader($rowData, $codeColumn, $priceColumn1)) {
                continue;
            }

            if (!$this->isDataRow($rowData, $codeColumn, $priceColumn1)) {
                continue;
            }

            $code = $this->normalizeCode($rowData[$codeIndex] ?? null);
            $name = $this->normalizeName($rowData[$nameIndex] ?? null);
            $price1 = $this->normalizePrice($rowData[$priceIndex1] ?? null);
            $price2 = isset($rowData[$priceIndex2]) ? $this->normalizePrice($rowData[$priceIndex2]) : null;

            if ($code === null && $name === null) {
                continue;
            }

            $data[] = [
                'code' => $code,
                'name' => $name,
                'price_1' => $price1,
                'price_2' => $price2
            ];
        }

        return $data;
    }

    private function extractRowData(Row $row): array
    {
        $rowData = [];
        $cells = $row->getCells();

        foreach ($cells as $cellIndex => $cell) {
            $text = '';
            foreach ($cell->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText();
                } elseif (method_exists($element, 'getTextObject')) {
                    $textObject = $element->getTextObject();
                    if ($textObject && method_exists($textObject, 'getText')) {
                        $text .= $textObject->getText();
                    }
                }
            }
            $rowData[$cellIndex] = trim($text);
        }

        return $rowData;
    }
}
