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

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Cannot read image info from: %s', $sourcePath));
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'] ?? '';

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
            throw new \RuntimeException(sprintf('Cannot create image resource from: %s', $sourcePath));
        }

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($resizedImage === false) {
            imagedestroy($sourceImage);
            throw new \RuntimeException('Cannot create resized image resource');
        }

        if ($mimeType === 'image/png') {
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

        $extension = $this->getExtensionFromMimeType($mimeType);
        $resizedPath = $this->generateTempPath($extension);

        try {
            $saved = match ($mimeType) {
                'image/jpeg' => imagejpeg($resizedImage, $resizedPath, 95),
                'image/png' => imagepng($resizedImage, $resizedPath, 9),
                default => false,
            };

            if (!$saved || !file_exists($resizedPath)) {
                throw new \RuntimeException('Failed to save resized image');
            }

            $this->logger->info('Successfully resized image', [
                'source' => $sourcePath,
                'destination' => $resizedPath,
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
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return false;
        }

        return $imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight;
    }

    private function createImageFromFile(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            default => null,
        };
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'tmp',
        };
    }

    private function generateTempPath(string $extension): string
    {
        $tempDir = sys_get_temp_dir();
        $filename = 'artiss_resized_' . bin2hex(random_bytes(8)) . '.' . $extension;

        return $tempDir . DIRECTORY_SEPARATOR . $filename;
    }
}
