<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Context;

class FileSaverDecorator extends FileSaver
{
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly FileSaver $decorated,
        private readonly ConfigService $configService,
        private readonly ImageFormatConverter $imageFormatConverter,
        private readonly ImageResizer $imageResizer,
        private readonly OriginalMediaArchiver $originalMediaArchiver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function persistFileToMedia(
        MediaFile $mediaFile,
        string $destination,
        string $mediaId,
        Context $context
    ): void {
        if (!$this->shouldProcess($mediaFile)) {
            $this->decorated->persistFileToMedia($mediaFile, $destination, $mediaId, $context);
            return;
        }

        $processedMediaFile = $this->processMediaFile($mediaFile, $mediaId, $context);
        $this->decorated->persistFileToMedia($processedMediaFile, $destination, $mediaId, $context);

        $this->cleanupTempFiles($mediaFile, $processedMediaFile);
    }

    public function renameMedia(string $mediaId, string $destination, Context $context): void
    {
        $this->decorated->renameMedia($mediaId, $destination, $context);
    }

    private function shouldProcess(MediaFile $mediaFile): bool
    {
        if (!$this->configService->isEnabled()) {
            return false;
        }

        return in_array($mediaFile->getMimeType(), self::SUPPORTED_MIME_TYPES, true);
    }

    private function processMediaFile(MediaFile $mediaFile, string $mediaId, Context $context): MediaFile
    {
        $sourcePath = $mediaFile->getFileName();
        $originalMimeType = $mediaFile->getMimeType();
        $originalExtension = $mediaFile->getFileExtension();
        $tempFilesToCleanup = [];

        try {
            if ($this->configService->shouldKeepOriginal()) {
                $this->originalMediaArchiver->archive(
                    $sourcePath,
                    $mediaId,
                    $originalExtension,
                    $this->configService->getOriginalStorageDir(),
                    $context
                );
            }

            $currentPath = $sourcePath;

            if ($this->configService->isResizeEnabled()) {
                $resizedPath = $this->imageResizer->resize(
                    $currentPath,
                    $this->configService->getMaxWidth(),
                    $this->configService->getMaxHeight()
                );

                if ($resizedPath !== null) {
                    $tempFilesToCleanup[] = $resizedPath;
                    $currentPath = $resizedPath;
                }
            }

            $webpPath = $this->imageFormatConverter->convertToWebp(
                $currentPath,
                $this->configService->getWebpQuality()
            );
            $tempFilesToCleanup[] = $webpPath;

            $this->logger->info('Successfully processed media file', [
                'mediaId' => $mediaId,
                'originalMime' => $originalMimeType,
                'newMime' => 'image/webp',
            ]);

            return new MediaFile(
                $webpPath,
                'image/webp',
                'webp',
                (int) filesize($webpPath)
            );
        } catch (\Throwable $e) {
            foreach ($tempFilesToCleanup as $tempFile) {
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }

            $this->logger->error('Failed to process media file', [
                'mediaId' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($this->configService->shouldFallbackOnError()) {
                $this->logger->warning('Falling back to original file due to conversion error', [
                    'mediaId' => $mediaId,
                ]);
                return $mediaFile;
            }

            throw new \RuntimeException(
                sprintf('Failed to convert media to WebP: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    private function cleanupTempFiles(MediaFile $original, MediaFile $processed): void
    {
        if ($original->getFileName() === $processed->getFileName()) {
            return;
        }

        $processedPath = $processed->getFileName();
        if (file_exists($processedPath) && str_starts_with(basename($processedPath), 'artiss_')) {
            @unlink($processedPath);
        }
    }
}
