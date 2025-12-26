<?php declare(strict_types=1);

namespace Artiss\Supplier\Service\Parser;

use Shopware\Core\Content\Media\MediaEntity;

/**
 * Interface for price list parsers
 * Supports Excel, CSV, and future AI-based parsing (PDF, images)
 */
interface PriceParserInterface
{
    /**
     * Check if this parser supports the given media file
     *
     * @param MediaEntity $media Media file to check
     * @return bool True if parser can handle this file
     */
    public function supports(MediaEntity $media): bool;

    /**
     * Parse price list file into normalized data structure
     *
     * @param MediaEntity $media Media file to parse
     * @param array $config Parser configuration
     *   - start_row: int - Row number where data starts (1-indexed)
     *   - code_column: string|int - Column identifier for product code (A, B, C... or 0, 1, 2...)
     *   - name_column: string|int - Column identifier for product name
     *   - price_column_1: string|int - First price column
     *   - price_column_2: string|int|null - Optional second price column
     *   - max_rows: int|null - Limit parsing to N rows (useful for preview)
     *
     * @return array Normalized data array in format:
     *   [
     *     ['code' => 'ABC123', 'name' => 'Product Name', 'price_1' => 100.00, 'price_2' => 150.00],
     *     ...
     *   ]
     *
     * @throws \RuntimeException If parsing fails
     */
    public function parse(MediaEntity $media, array $config): array;

    /**
     * Get preview of file structure (first N rows) for column mapping
     *
     * @param MediaEntity $media Media file to preview
     * @param int $previewRows Number of rows to preview (default 5)
     *
     * @return array Preview data in format:
     *   [
     *     'headers' => ['A' => 'Code', 'B' => 'Name', 'C' => 'Price', ...],
     *     'rows' => [
     *       ['A' => 'ABC123', 'B' => 'Product', 'C' => '100', ...],
     *       ...
     *     ],
     *     'suggested_start_row' => 2, // Auto-detected data start row
     *   ]
     */
    public function preview(MediaEntity $media, int $previewRows = 5): array;

    /**
     * Get parser name for display
     *
     * @return string Parser name (e.g., "Excel Parser", "CSV Parser", "AI Parser")
     */
    public function getName(): string;

    /**
     * Get supported file extensions
     *
     * @return array Supported extensions (e.g., ['xlsx', 'xls'])
     */
    public function getSupportedExtensions(): array;
}
