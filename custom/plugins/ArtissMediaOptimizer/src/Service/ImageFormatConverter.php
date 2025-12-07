<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Psr\Log\LoggerInterface;

class ImageFormatConverter
{
    /**
     * Supported MIME types for conversion to WebP.
     * Note: Animated GIFs will lose animation when converted.
     */
    public const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/tiff',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function convertToWebp(string $sourcePath, int $quality = 85): string
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException(sprintf('Source file not found: %s', $sourcePath));
        }

        $quality = max(0, min(100, $quality));
        $mimeType = $this->detectMimeType($sourcePath);

        if (!$this->supportsConversion($mimeType)) {
            throw new \RuntimeException(sprintf('Unsupported image format: %s', $mimeType));
        }

        $sourceImage = $this->createImageFromFile($sourcePath, $mimeType);

        if ($sourceImage === null) {
            throw new \RuntimeException(sprintf('Cannot create image resource from: %s (mime: %s)', $sourcePath, $mimeType));
        }

        $webpPath = $this->generateTempPath('webp');

        try {
            imagepalettetotruecolor($sourceImage);
            imagealphablending($sourceImage, true);
            imagesavealpha($sourceImage, true);

            $result = imagewebp($sourceImage, $webpPath, $quality);

            if (!$result || !file_exists($webpPath)) {
                throw new \RuntimeException('Failed to create WebP file');
            }

            $this->logger->info('Successfully converted image to WebP', [
                'source' => $sourcePath,
                'destination' => $webpPath,
                'sourceMime' => $mimeType,
                'quality' => $quality,
                'originalSize' => filesize($sourcePath),
                'newSize' => filesize($webpPath),
            ]);

            return $webpPath;
        } catch (\Throwable $e) {
            if (file_exists($webpPath)) {
                @unlink($webpPath);
            }
            throw $e;
        } finally {
            imagedestroy($sourceImage);
        }
    }

    public function supportsConversion(string $mimeType): bool
    {
        // Check if basic support exists
        if (!in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            return false;
        }

        // TIFF requires Imagick extension
        if ($mimeType === 'image/tiff') {
            return extension_loaded('imagick');
        }

        // HEIC/HEIF requires Imagick extension
        if (in_array($mimeType, ['image/heic', 'image/heif'], true)) {
            return extension_loaded('imagick') && $this->imagickSupportsHeic();
        }

        return true;
    }

    /**
     * Check if Imagick supports HEIC format.
     */
    private function imagickSupportsHeic(): bool
    {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $formats = \Imagick::queryFormats('HEIC');
            return !empty($formats);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Detect MIME type from file content (more reliable than relying on extension).
     */
    private function detectMimeType(string $path): string
    {
        // Try getimagesize first (most reliable for images)
        $imageInfo = @getimagesize($path);
        if ($imageInfo !== false && !empty($imageInfo['mime'])) {
            return $imageInfo['mime'];
        }

        // Fallback to mime_content_type
        $mimeType = @mime_content_type($path);
        if ($mimeType !== false) {
            return $mimeType;
        }

        // Check file signature for HEIC/HEIF (not detected by getimagesize)
        $handle = @fopen($path, 'rb');
        if ($handle) {
            $header = fread($handle, 12);
            fclose($handle);

            // HEIC/HEIF signature: ftyp followed by heic, heix, hevc, mif1, etc.
            if (str_contains($header, 'ftyp')) {
                if (str_contains($header, 'heic') || str_contains($header, 'heix') || str_contains($header, 'hevc')) {
                    return 'image/heic';
                }
                if (str_contains($header, 'mif1') || str_contains($header, 'msf1')) {
                    return 'image/heif';
                }
            }
        }

        return '';
    }

    private function createImageFromFile(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => $this->createFromPngWithAlpha($path),
            'image/gif' => $this->createFromGif($path),
            'image/bmp', 'image/x-ms-bmp' => $this->createFromBmp($path),
            'image/tiff' => $this->createFromTiff($path),
            'image/heic', 'image/heif' => $this->createFromHeic($path),
            default => null,
        };
    }

    /**
     * Create GD image from GIF.
     * Note: Animated GIFs will only use the first frame.
     */
    private function createFromGif(string $path): ?\GdImage
    {
        $image = @imagecreatefromgif($path);
        if ($image === false) {
            return null;
        }

        // Convert to truecolor to preserve transparency
        $width = imagesx($image);
        $height = imagesy($image);

        $trueColorImage = imagecreatetruecolor($width, $height);
        if ($trueColorImage === false) {
            imagedestroy($image);
            return null;
        }

        // Handle transparency
        imagealphablending($trueColorImage, false);
        imagesavealpha($trueColorImage, true);
        $transparent = imagecolorallocatealpha($trueColorImage, 255, 255, 255, 127);
        imagefilledrectangle($trueColorImage, 0, 0, $width, $height, $transparent);

        imagealphablending($trueColorImage, true);
        imagecopy($trueColorImage, $image, 0, 0, 0, 0, $width, $height);

        imagedestroy($image);

        return $trueColorImage;
    }

    /**
     * Create GD image from BMP.
     */
    private function createFromBmp(string $path): ?\GdImage
    {
        // PHP 7.2+ has native BMP support
        if (function_exists('imagecreatefrombmp')) {
            $image = @imagecreatefrombmp($path);
            return $image !== false ? $image : null;
        }

        $this->logger->warning('BMP support requires PHP 7.2+ with GD');
        return null;
    }

    /**
     * Create GD image from TIFF using Imagick.
     */
    private function createFromTiff(string $path): ?\GdImage
    {
        if (!extension_loaded('imagick')) {
            $this->logger->warning('TIFF conversion requires Imagick extension');
            return null;
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->setImageFormat('png');

            // Write to temp file and read with GD
            $tempPng = $this->generateTempPath('png');
            $imagick->writeImage($tempPng);
            $imagick->clear();
            $imagick->destroy();

            $gdImage = @imagecreatefrompng($tempPng);
            @unlink($tempPng);

            return $gdImage !== false ? $gdImage : null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to convert TIFF with Imagick', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create GD image from HEIC/HEIF using Imagick.
     */
    private function createFromHeic(string $path): ?\GdImage
    {
        if (!extension_loaded('imagick')) {
            $this->logger->warning('HEIC/HEIF conversion requires Imagick extension');
            return null;
        }

        if (!$this->imagickSupportsHeic()) {
            $this->logger->warning('Imagick does not support HEIC format. Install libheif.');
            return null;
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->setImageFormat('png');

            // Write to temp file and read with GD
            $tempPng = $this->generateTempPath('png');
            $imagick->writeImage($tempPng);
            $imagick->clear();
            $imagick->destroy();

            $gdImage = @imagecreatefrompng($tempPng);
            @unlink($tempPng);

            return $gdImage !== false ? $gdImage : null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to convert HEIC with Imagick', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function createFromPngWithAlpha(string $path): ?\GdImage
    {
        $image = @imagecreatefrompng($path);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $trueColorImage = imagecreatetruecolor($width, $height);
        if ($trueColorImage === false) {
            imagedestroy($image);
            return null;
        }

        imagealphablending($trueColorImage, false);
        imagesavealpha($trueColorImage, true);

        $transparent = imagecolorallocatealpha($trueColorImage, 255, 255, 255, 127);
        imagefilledrectangle($trueColorImage, 0, 0, $width, $height, $transparent);

        imagealphablending($trueColorImage, true);
        imagecopy($trueColorImage, $image, 0, 0, 0, 0, $width, $height);

        imagedestroy($image);

        return $trueColorImage;
    }

    private function generateTempPath(string $extension): string
    {
        $tempDir = sys_get_temp_dir();
        $filename = 'artiss_media_' . bin2hex(random_bytes(8)) . '.' . $extension;

        return $tempDir . DIRECTORY_SEPARATOR . $filename;
    }
}
