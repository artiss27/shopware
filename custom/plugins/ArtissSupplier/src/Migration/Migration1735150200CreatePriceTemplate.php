<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1735150200CreatePriceTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735150200;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `art_supplier_price_template` (
    `id` BINARY(16) NOT NULL,
    `supplier_id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `config` JSON NULL,
    `last_import_media_id` BINARY(16) NULL,
    `last_import_media_updated_at` DATETIME(3) NULL,
    `normalized_data` LONGTEXT NULL,
    `matched_products` JSON NULL,
    `applied_at` DATETIME(3) NULL,
    `applied_by_user_id` BINARY(16) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NULL ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    CONSTRAINT `fk.art_supplier_price_template.supplier_id` FOREIGN KEY (`supplier_id`)
        REFERENCES `art_supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.art_supplier_price_template.last_import_media_id` FOREIGN KEY (`last_import_media_id`)
        REFERENCES `media` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk.art_supplier_price_template.applied_by_user_id` FOREIGN KEY (`applied_by_user_id`)
        REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    KEY `idx.art_supplier_price_template.supplier_id` (`supplier_id`),
    KEY `idx.art_supplier_price_template.last_import_media_id` (`last_import_media_id`),
    KEY `idx.art_supplier_price_template.applied_by_user_id` (`applied_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Drop table if needed during plugin uninstall
        // $connection->executeStatement('DROP TABLE IF EXISTS `art_supplier_price_template`');
    }
}
