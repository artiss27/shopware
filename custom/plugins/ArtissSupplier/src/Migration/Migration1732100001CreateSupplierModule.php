<?php
declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1732100001CreateSupplierModule extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732100001;
    }

    public function update(Connection $connection): void
    {
        // Create supplier table
        // Note: Product-supplier relation stored in product.custom_fields.supplier_id (no DB column needed)
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `art_supplier` (
    `id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `manufacturer_ids` JSON NULL,
    `equipment_type_ids` JSON NULL,
    `custom_fields` JSON NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `art_supplier`');
    }
}
