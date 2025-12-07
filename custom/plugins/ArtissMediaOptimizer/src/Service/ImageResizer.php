<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Psr\Log\LoggerInterface;

class ImageResizer
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function resize(string $sourcePath, int $maxWidth, int $maxHeight): ?string
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException(sprintf('Source file not found: %s', $sourcePath));
        }

        $mimeType = $this->detectMimeType($sourcePath);
        $dimensions = $this->getImageDimensions($sourcePath, $mimeType);

        if ($dimensions === null) {
            throw new \RuntimeException(sprintf('Cannot read image dimensions from: %s', $sourcePath));
        }

        [$originalWidth, $originalHeight] = $dimensions;

        if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
            $this->logger->debug('Image does not need resizing', [
                'path' => $sourcePath,
                'width' => $originalWidth,
                'height' => $originalHeight,
                'maxWidth' => $maxWidth,
                'maxHeight' => $maxHeight,
            ]);
            return null;
        }

        $scale = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int) floor($originalWidth * $scale);
        $newHeight = (int) floor($originalHeight * $scale);

        $sourceImage = $this->createImageFromFile($sourcePath, $mimeType);
        if ($sourceImage === null) {
            throw new \RuntimeException(sprintf('Cannot create image resource from: %s (mime: %s)', $sourcePath, $mimeType));
        }

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($resizedImage === false) {
            imagedestroy($sourceImage);
            throw new \RuntimeException('Cannot create resized image resource');
        }

        // Handle transparency for PNG and GIF
        if (in_array($mimeType, ['image/png', 'image/gif'], true)) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0, 0,
            0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        // Always save as PNG to preserve quality for next conversion step
        $resizedPath = $this->generateTempPath('png');

        try {
            $saved = imagepng($resizedImage, $resizedPath, 1); // Low compression, high quality

            if (!$saved || !file_exists($resizedPath)) {
                throw new \RuntimeException('Failed to save resized image');
            }

            $this->logger->info('Successfully resized image', [
                'source' => $sourcePath,
                'destination' => $resizedPath,
                'sourceMime' => $mimeType,
                'originalSize' => "{$originalWidth}x{$originalHeight}",
                'newSize' => "{$newWidth}x{$newHeight}",
            ]);

            return $resizedPath;
        } catch (\Throwable $e) {
            if (file_exists($resizedPath)) {
                @unlink($resizedPath);
            }
            throw $e;
        } finally {
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);
        }
    }

    public function needsResize(string $sourcePath, int $maxWidth, int $maxHeight): bool
    {
        $mimeType = $this->detectMimeType($sourcePath);
        $dimensions = $this->getImageDimensions($sourcePath, $mimeType);

        if ($dimensions === null) {
            return false;
        }

        return $dimensions[0] > $maxWidth || $dimensions[1] > $maxHeight;
    }

    /**
     * Detect MIME type from file content.
     */
    private function detectMimeType(string $path): string
    {
        $imageInfo = @getimagesize($path);
        if ($imageInfo !== false && !empty($imageInfo['mime'])) {
            return $imageInfo['mime'];
        }

        $mimeType = @mime_content_type($path);
        if ($mimeType !== false) {
            return $mimeType;
        }

        // Check file signature for HEIC/HEIF
        $handle = @fopen($path, 'rb');
        if ($handle) {
            $header = fread($handle, 12);
            fclose($handle);

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

    /**
     * Get image dimensions, handling formats that getimagesize doesn't support.
     */
    private function getImageDimensions(string $path, string $mimeType): ?array
    {
        // Standard formats - use getimagesize
        $imageInfo = @getimagesize($path);
        if ($imageInfo !== false) {
            return [$imageInfo[0], $imageInfo[1]];
        }

        // HEIC/HEIF and TIFF - use Imagick if available
        if (in_array($mimeType, ['image/heic', 'image/heif', 'image/tiff'], true) && extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($path);
                $width = $imagick->getImageWidth();
                $height = $imagick->getImageHeight();
                $imagick->clear();
                $imagick->destroy();
                return [$width, $height];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function createImageFromFile(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/gif' => $this->createFromGif($path),
            'image/bmp', 'image/x-ms-bmp' => $this->createFromBmp($path),
            'image/tiff' => $this->createFromTiff($path),
            'image/heic', 'image/heif' => $this->createFromHeic($path),
            default => null,
        };
    }

    private function createFromGif(string $path): ?\GdImage
    {
        $image = @imagecreatefromgif($path);
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

    private function createFromBmp(string $path): ?\GdImage
    {
        if (function_exists('imagecreatefrombmp')) {
            $image = @imagecreatefrombmp($path);
            return $image !== false ? $image : null;
        }

        $this->logger->warning('BMP support requires PHP 7.2+ with GD');
        return null;
    }

    private function createFromTiff(string $path): ?\GdImage
    {
        if (!extension_loaded('imagick')) {
            $this->logger->warning('TIFF resize requires Imagick extension');
            return null;
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->setImageFormat('png');

            $tempPng = $this->generateTempPath('png');
            $imagick->writeImage($tempPng);
            $imagick->clear();
            $imagick->destroy();

            $gdImage = @imagecreatefrompng($tempPng);
            @unlink($tempPng);

            return $gdImage !== false ? $gdImage : null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load TIFF with Imagick', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function createFromHeic(string $path): ?\GdImage
    {
        if (!extension_loaded('imagick')) {
            $this->logger->warning('HEIC/HEIF resize requires Imagick extension');
            return null;
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->setImageFormat('png');

            $tempPng = $this->generateTempPath('png');
            $imagick->writeImage($tempPng);
            $imagick->clear();
            $imagick->destroy();

            $gdImage = @imagecreatefrompng($tempPng);
            @unlink($tempPng);

            return $gdImage !== false ? $gdImage : null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load HEIC with Imagick', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function generateTempPath(string $extension): string
    {
        $tempDir = sys_get_temp_dir();
        $filename = 'artiss_resized_' . bin2hex(random_bytes(8)) . '.' . $extension;

        return $tempDir . DIRECTORY_SEPARATOR . $filename;
    }
}
