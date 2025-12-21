<?php
declare(strict_types=1);

namespace ArtissTools\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1734796800CreateMediaHashTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1734796800;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `art_media_hash` (
    `media_id` BINARY(16) NOT NULL,
    `hash` VARCHAR(64) NOT NULL COMMENT 'MD5/SHA1 hash of file content',
    `size` BIGINT NOT NULL COMMENT 'File size in bytes',
    `width` INT NULL COMMENT 'Image width in pixels',
    `height` INT NULL COMMENT 'Image height in pixels',
    `updated_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`media_id`),
    INDEX `idx_hash_size` (`hash`, `size`),
    INDEX `idx_updated_at` (`updated_at`),
    CONSTRAINT `fk_artiss_media_hash_media_id`
        FOREIGN KEY (`media_id`)
        REFERENCES `media` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `art_media_hash`');
    }
}
