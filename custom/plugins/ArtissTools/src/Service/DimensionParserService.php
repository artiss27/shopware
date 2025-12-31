<?php declare(strict_types=1);

namespace ArtissTools\Service;

use Psr\Log\LoggerInterface;

class DimensionParserService
{
    private const UNIT_CONVERSIONS = [
        'мм' => 1.0,
        'mm' => 1.0,
        'см' => 10.0,
        'cm' => 10.0,
        'м' => 1000.0,
        'm' => 1000.0,
        'дм' => 100.0,
        'dm' => 100.0,
    ];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $dimensionString Format: "549х595х570 мм", "549x595x570 mm", "35х30 см", "220 мм"
     * @return array{width: float, height: float, length: float}|null Returns dimensions in millimeters
     */
    public function parseDimensions(string $dimensionString): ?array
    {
        if (empty(trim($dimensionString))) {
            return null;
        }

        $normalized = mb_strtolower(trim($dimensionString));
        $unit = null;
        $unitMultiplier = 1.0;
        
        foreach (self::UNIT_CONVERSIONS as $unitName => $multiplier) {
            if (mb_substr($normalized, -mb_strlen($unitName)) === $unitName) {
                $unit = $unitName;
                $unitMultiplier = $multiplier;
                $normalized = trim(mb_substr($normalized, 0, -mb_strlen($unitName)));
                break;
            }
        }

        $normalized = str_replace(['х', 'X', '*', '×', '×'], 'x', $normalized);
        $parts = null;
        
        if (mb_strpos($normalized, 'x') !== false) {
            $parts = array_map('trim', explode('x', $normalized));
        }

        if ($parts === null) {
            $parts = preg_split('/\s+/', $normalized);
        }

        $parts = array_filter($parts, fn($part) => !empty(trim($part)));
        $parts = array_values($parts);

        if (count($parts) < 1) {
            $this->logger->warning('DimensionParser: Insufficient parts in dimension string', [
                'input' => $dimensionString,
                'parts' => $parts,
            ]);
            return null;
        }

        if (count($parts) === 1) {
            $parts = ['0', '0', $parts[0]];
        } elseif (count($parts) === 2) {
            $parts = [$parts[0], '0', $parts[1]];
        } else {
            $parts = array_slice($parts, 0, 3);
        }

        $dimensions = [];
        foreach ($parts as $index => $part) {
            $cleaned = preg_replace('/[^\d.,]/', '', $part);
            $cleaned = str_replace(',', '.', $cleaned);
            
            $value = filter_var($cleaned, FILTER_VALIDATE_FLOAT);
            
            if ($value === false || $value < 0 || ($value == 0 && $index === 2)) {
                $this->logger->warning('DimensionParser: Failed to parse number', [
                    'input' => $dimensionString,
                    'part' => $part,
                    'cleaned' => $cleaned,
                    'index' => $index,
                    'value' => $value,
                ]);
                return null;
            }

            $dimensions[] = $value * $unitMultiplier;
        }

        if (count($dimensions) !== 3) {
            return null;
        }

        return [
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'length' => $dimensions[2],
        ];
    }

    public function hasDimensions(?float $width, ?float $height, ?float $length): bool
    {
        return ($width !== null && $width > 0) 
            || ($height !== null && $height > 0) 
            || ($length !== null && $length > 0);
    }
}
