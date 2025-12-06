<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Psr\Log\LoggerInterface;

class ImageFormatConverter
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function convertToWebp(string $sourcePath, int $quality = 80): string
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException(sprintf('Source file not found: %s', $sourcePath));
        }

        $quality = max(0, min(100, $quality));

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \RuntimeException(sprintf('Cannot read image info from: %s', $sourcePath));
        }

        $mimeType = $imageInfo['mime'] ?? '';
        $sourceImage = $this->createImageFromFile($sourcePath, $mimeType);

        if ($sourceImage === null) {
            throw new \RuntimeException(sprintf('Cannot create image resource from: %s', $sourcePath));
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
        return in_array($mimeType, ['image/jpeg', 'image/png'], true);
    }

    private function createImageFromFile(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => $this->createFromPngWithAlpha($path),
            default => null,
        };
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
