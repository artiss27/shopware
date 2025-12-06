<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class OriginalMediaArchiver
{
    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir
    ) {
    }

    public function archive(
        string $sourcePath,
        string $mediaId,
        string $originalExtension,
        string $storageBaseDir,
        Context $context
    ): string {
        $relativePath = $this->generateRelativePath($mediaId, $originalExtension);
        $absolutePath = $this->getAbsolutePath($storageBaseDir, $relativePath);

        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Cannot create directory: %s', $directory));
            }
        }

        if (!copy($sourcePath, $absolutePath)) {
            throw new \RuntimeException(sprintf('Cannot copy file from %s to %s', $sourcePath, $absolutePath));
        }

        $this->updateMediaCustomFields($mediaId, $relativePath, $context);

        $this->logger->info('Archived original media file', [
            'mediaId' => $mediaId,
            'source' => $sourcePath,
            'archivePath' => $absolutePath,
            'relativePath' => $relativePath,
        ]);

        return $relativePath;
    }

    public function getArchivedPath(string $mediaId, string $storageBaseDir, Context $context): ?string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$mediaId]);
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if ($media === null) {
            return null;
        }

        $customFields = $media->getCustomFields() ?? [];
        $relativePath = $customFields['artiss_original_path'] ?? null;

        if ($relativePath === null) {
            return null;
        }

        return $this->getAbsolutePath($storageBaseDir, $relativePath);
    }

    public function deleteArchived(string $mediaId, string $storageBaseDir, Context $context): bool
    {
        $absolutePath = $this->getArchivedPath($mediaId, $storageBaseDir, $context);

        if ($absolutePath === null || !file_exists($absolutePath)) {
            return false;
        }

        if (!unlink($absolutePath)) {
            $this->logger->warning('Failed to delete archived original', [
                'mediaId' => $mediaId,
                'path' => $absolutePath,
            ]);
            return false;
        }

        $this->clearMediaCustomField($mediaId, $context);

        $this->logger->info('Deleted archived original', [
            'mediaId' => $mediaId,
            'path' => $absolutePath,
        ]);

        return true;
    }

    private function generateRelativePath(string $mediaId, string $extension): string
    {
        $hash = md5($mediaId);
        $subDir1 = substr($hash, 0, 2);
        $subDir2 = substr($hash, 2, 2);

        return sprintf('%s/%s/%s.%s', $subDir1, $subDir2, $mediaId, $extension);
    }

    private function getAbsolutePath(string $storageBaseDir, string $relativePath): string
    {
        return sprintf(
            '%s/var/%s/%s',
            rtrim($this->projectDir, '/'),
            trim($storageBaseDir, '/'),
            ltrim($relativePath, '/')
        );
    }

    private function updateMediaCustomFields(string $mediaId, string $relativePath, Context $context): void
    {
        $this->mediaRepository->update([
            [
                'id' => $mediaId,
                'customFields' => [
                    'artiss_original_path' => $relativePath,
                ],
            ],
        ], $context);
    }

    private function clearMediaCustomField(string $mediaId, Context $context): void
    {
        $this->mediaRepository->update([
            [
                'id' => $mediaId,
                'customFields' => [
                    'artiss_original_path' => null,
                ],
            ],
        ], $context);
    }
}
