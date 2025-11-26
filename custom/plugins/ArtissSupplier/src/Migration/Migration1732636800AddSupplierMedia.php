<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1732636800AddSupplierMedia extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732636800;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `art_supplier_media` (
    `supplier_id` BINARY(16) NOT NULL,
    `media_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`supplier_id`, `media_id`),
    CONSTRAINT `fk.art_supplier_media.supplier_id` FOREIGN KEY (`supplier_id`)
        REFERENCES `art_supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.art_supplier_media.media_id` FOREIGN KEY (`media_id`)
        REFERENCES `media` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
