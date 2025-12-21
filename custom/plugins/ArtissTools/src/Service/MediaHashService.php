<?php
declare(strict_types=1);

namespace ArtissTools\Service;

use AllowDynamicProperties;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\KernelInterface;

#[AllowDynamicProperties]
class MediaHashService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository,
        private readonly KernelInterface $kernel
    ) {
    }

    /**
     * Get last update time for media hashes
     */
    public function getLastHashUpdate(Context $context): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT MAX(updated_at) as last_update FROM art_media_hash'
        );

        if (!$result || !$result['last_update']) {
            return null;
        }

        return [
            'lastUpdate' => $result['last_update'],
            'totalHashed' => $this->getTotalHashedMedia()
        ];
    }

    /**
     * Get total count of hashed media
     */
    public function getTotalHashedMedia(): int
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM art_media_hash'
        );

        return (int)$result;
    }

    /**
     * Clear all hashes (for recalculation)
     */
    public function clearAllHashes(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE art_media_hash');
    }

    /**
     * Update hashes for a batch of media files
     * Returns number of media processed and whether there are more to process
     */
    public function updateMediaHashesBatch(
        int $batchSize = 100,
        int $offset = 0,
        ?string $folderEntity = null,
        Context $context = null,
        bool $force = false,
        bool $onlyMissing = false
    ): array {
        if ($context === null) {
            $context = Context::createDefaultContext();
        }

        if ($force && $offset === 0) {
            $this->clearAllHashes();
            $onlyMissing = true;
        }

        $criteria = new Criteria();
        $criteria->setLimit($batchSize);
        $criteria->addSorting(new FieldSorting('id', 'ASC'));

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('uploadedAt', null)
        ]));

        if ($onlyMissing) {
            $hashedMediaIds = $this->connection->fetchFirstColumn(
                'SELECT HEX(media_id) FROM art_media_hash'
            );
            if (!empty($hashedMediaIds)) {
                $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('id', $hashedMediaIds)
                ]));
            }
        }

        if ($folderEntity) {
            $criteria->addAssociation('mediaFolder.defaultFolder');
            $criteria->addFilter(new EqualsFilter('mediaFolder.defaultFolder.entity', $folderEntity));
        }

        if (!empty($this->lastProcessedId)) {
            $criteria->addFilter(new RangeFilter('id', ['gt' => $this->lastProcessedId]));
        }

        $mediaCollection = $this->mediaRepository->search($criteria, $context);

        $processed = 0;
        $projectDir = $this->kernel->getProjectDir();
        $lastProcessedId = $this->lastProcessedId ?? null;

        foreach ($mediaCollection as $media) {
            $this->updateMediaHash($media, $projectDir, $context);
            $processed++;
            $lastProcessedId = $media->getId();
        }

        $this->lastProcessedId = $lastProcessedId;

        $hasMore = $processed === $batchSize;
        $totalHashed = $this->getTotalHashedMedia();

        return [
            'processed'   => $processed,
            'hasMore'     => $hasMore,
            'totalHashed' => $totalHashed,
            'nextOffset'  => $lastProcessedId,
        ];
    }

    /**
     * Update hash for a single media file
     */
    private function updateMediaHash(MediaEntity $media, string $projectDir, Context $context): void
    {
        $mediaPath = $projectDir . '/public/' . $media->getPath();

        if (!file_exists($mediaPath)) {
            return;
        }

        $fileSize = filesize($mediaPath);
        $fileHash = md5_file($mediaPath);

        if ($fileHash === false) {
            return;
        }

        // Get image dimensions if it's an image
        $width = null;
        $height = null;

        if ($media->getMimeType() && str_starts_with($media->getMimeType(), 'image/')) {
            $imageInfo = @getimagesize($mediaPath);
            if ($imageInfo !== false) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        // Insert or update hash record
        $mediaIdBytes = Uuid::fromHexToBytes($media->getId());

        $this->connection->executeStatement(
            'INSERT INTO art_media_hash (media_id, hash, size, width, height, updated_at)
             VALUES (:media_id, :hash, :size, :width, :height, :updated_at)
             ON DUPLICATE KEY UPDATE
                hash = VALUES(hash),
                size = VALUES(size),
                width = VALUES(width),
                height = VALUES(height),
                updated_at = VALUES(updated_at)',
            [
                'media_id' => $mediaIdBytes,
                'hash' => $fileHash,
                'size' => $fileSize,
                'width' => $width,
                'height' => $height,
                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s.u')
            ]
        );
    }
}
