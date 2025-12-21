<?php
declare(strict_types=1);

namespace ArtissTools\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class MediaDuplicatesService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository
    ) {
    }

    /**
     * Find next duplicate set
     * Returns one group of duplicates (by hash + size)
     */
    public function findNextDuplicateSet(?string $folderEntity = null, Context $context = null): ?array
    {
        if ($context === null) {
            $context = Context::createDefaultContext();
        }

        // Build SQL to find duplicate groups
        $sql = '
            SELECT h.hash, h.size, COUNT(*) as duplicate_count
            FROM art_media_hash h
        ';

        $params = [];

        if ($folderEntity) {
            // Join with media and media_folder to filter by folder entity
            $sql .= '
                INNER JOIN media m ON h.media_id = m.id
                INNER JOIN media_folder mf ON m.media_folder_id = mf.id
                INNER JOIN media_default_folder mdf ON mf.default_folder_id = mdf.id
                WHERE mdf.entity = :folder_entity
            ';
            $params['folder_entity'] = $folderEntity;
        }

        $sql .= '
            GROUP BY h.hash, h.size
            HAVING COUNT(*) > 1
            LIMIT 1
        ';

        $duplicateGroup = $this->connection->fetchAssociative($sql, $params);

        if (!$duplicateGroup) {
            return null;
        }

        // Get all media IDs in this duplicate group
        $mediaIds = $this->connection->fetchFirstColumn(
            'SELECT HEX(media_id) as media_id
             FROM art_media_hash
             WHERE hash = :hash AND size = :size',
            [
                'hash' => $duplicateGroup['hash'],
                'size' => $duplicateGroup['size']
            ]
        );

        // Load full media entities
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('id', $mediaIds));
        $criteria->addAssociation('mediaFolder.defaultFolder');

        $mediaCollection = $this->mediaRepository->search($criteria, $context);

        // Calculate usage statistics for each media
        $mediaData = [];
        foreach ($mediaCollection as $media) {
            $usage = $this->calculateMediaUsage($media->getId());

            $mediaData[] = [
                'id' => $media->getId(),
                'fileName' => $media->getFileName(),
                'path' => $media->getPath(),
                'mimeType' => $media->getMimeType(),
                'fileSize' => $media->getFileSize(),
                'createdAt' => $media->getCreatedAt() ? $media->getCreatedAt()->format('Y-m-d H:i:s') : null,
                'folder' => $media->getMediaFolder() ? $media->getMediaFolder()->getName() : null,
                'folderEntity' => $media->getMediaFolder() && $media->getMediaFolder()->getDefaultFolder()
                    ? $media->getMediaFolder()->getDefaultFolder()->getEntity()
                    : null,
                'usage' => $usage,
                'usedInParentProduct' => $usage['usedInParentProduct']
            ];
        }

        // Determine keeper media ID (по логике из ТЗ)
        $keeperMediaId = $this->determineKeeperMedia($mediaData);

        return [
            'hash' => $duplicateGroup['hash'],
            'size' => $duplicateGroup['size'],
            'duplicateCount' => $duplicateGroup['duplicate_count'],
            'mediaList' => $mediaData,
            'keeperMediaId' => $keeperMediaId
        ];
    }

    /**
     * Calculate usage statistics for a media
     */
    private function calculateMediaUsage(string $mediaId): array
    {
        $mediaIdBytes = Uuid::fromHexToBytes($mediaId);

        // Check product_media usage
        $productMediaCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM product_media WHERE media_id = :media_id',
            ['media_id' => $mediaIdBytes]
        );

        // Check product cover usage (через product_media_id)
        $productCoverCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM product p
             INNER JOIN product_media pm ON p.product_media_id = pm.id
             WHERE pm.media_id = :media_id',
            ['media_id' => $mediaIdBytes]
        );

        // Check if used in parent product (product.parent_id IS NULL)
        $usedInParentProduct = (bool)$this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM product_media pm
             INNER JOIN product p ON pm.product_id = p.id
             WHERE pm.media_id = :media_id AND p.parent_id IS NULL',
            ['media_id' => $mediaIdBytes]
        );

        // Check category usage
        $categoryCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM category WHERE media_id = :media_id',
            ['media_id' => $mediaIdBytes]
        );

        // Check CMS usage (cms_slot_translation)
        $cmsCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(DISTINCT cms_slot_id)
             FROM cms_slot_translation
             WHERE JSON_CONTAINS(config, JSON_QUOTE(:media_id))',
            ['media_id' => $mediaId]
        );

        return [
            'product' => $productMediaCount,
            'productCover' => $productCoverCount,
            'category' => $categoryCount,
            'cms' => $cmsCount,
            'total' => $productMediaCount + $productCoverCount + $categoryCount + $cmsCount,
            'usedInParentProduct' => $usedInParentProduct
        ];
    }

    /**
     * Determine keeper media based on TZ logic:
     * 1. If есть media used in parent product -> oldest createdAt among them
     * 2. Else -> oldest createdAt in group
     */
    private function determineKeeperMedia(array $mediaData): string
    {
        // Sort by: usedInParentProduct DESC, createdAt ASC
        usort($mediaData, function ($a, $b) {
            // First priority: used in parent product
            if ($a['usedInParentProduct'] !== $b['usedInParentProduct']) {
                return $b['usedInParentProduct'] <=> $a['usedInParentProduct'];
            }
            // Second priority: oldest createdAt
            return $a['createdAt'] <=> $b['createdAt'];
        });

        return $mediaData[0]['id'];
    }

    /**
     * Merge duplicate set
     * Update all references to duplicates to point to keeper
     */
    public function mergeDuplicateSet(string $keeperMediaId, array $duplicateMediaIds, Context $context = null): array
    {
        if ($context === null) {
            $context = Context::createDefaultContext();
        }

        $keeperIdBytes = Uuid::fromHexToBytes($keeperMediaId);
        $duplicateIdsBytes = array_map(fn($id) => Uuid::fromHexToBytes($id), $duplicateMediaIds);

        $updatedReferences = 0;

        foreach ($duplicateIdsBytes as $duplicateIdBytes) {
            // Update product_media
            $updatedReferences += $this->connection->executeStatement(
                'UPDATE product_media SET media_id = :keeper_id WHERE media_id = :duplicate_id',
                ['keeper_id' => $keeperIdBytes, 'duplicate_id' => $duplicateIdBytes]
            );

            // Update product.product_media_id (cover)
            // First find product_media records with keeper media
            $keeperProductMediaIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM product_media WHERE media_id = :keeper_id',
                ['keeper_id' => $keeperIdBytes]
            );

            if (!empty($keeperProductMediaIds)) {
                // Update products that use duplicate as cover to use keeper's product_media
                $this->connection->executeStatement(
                    'UPDATE product p
                     INNER JOIN product_media pm ON p.product_media_id = pm.id
                     SET p.product_media_id = :keeper_pm_id
                     WHERE pm.media_id = :duplicate_id',
                    [
                        'keeper_pm_id' => $keeperProductMediaIds[0],
                        'duplicate_id' => $duplicateIdBytes
                    ]
                );
            }

            // Update category.media_id
            $updatedReferences += $this->connection->executeStatement(
                'UPDATE category SET media_id = :keeper_id WHERE media_id = :duplicate_id',
                ['keeper_id' => $keeperIdBytes, 'duplicate_id' => $duplicateIdBytes]
            );

            // Update CMS slots (JSON field - more complex)
            // Note: This is a simplified approach. For production, you might need more sophisticated JSON handling
            $this->connection->executeStatement(
                'UPDATE cms_slot_translation
                 SET config = JSON_REPLACE(config, \'$.media.value\', :keeper_id)
                 WHERE JSON_CONTAINS(config, JSON_QUOTE(:duplicate_id))',
                ['keeper_id' => $keeperMediaId, 'duplicate_id' => Uuid::fromBytesToHex($duplicateIdBytes)]
            );
        }

        return [
            'keeperMediaId' => $keeperMediaId,
            'duplicateMediaIds' => $duplicateMediaIds,
            'updatedReferencesCount' => $updatedReferences
        ];
    }
}
