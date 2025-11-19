<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1700000001CreateSupplierTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000001;
    }

    public function update(Connection $connection): void
    {
        // Create supplier table
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `supplier` (
    `id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `code` VARCHAR(255) NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort` INT(11) NOT NULL DEFAULT 500,
    `bitrix_id` INT(11) NULL,
    `custom_fields` JSON NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_supplier_bitrix_id` (`bitrix_id`),
    KEY `idx_supplier_code` (`code`),
    KEY `idx_supplier_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);

        // Create supplier_manufacturer mapping table
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `supplier_manufacturer` (
    `supplier_id` BINARY(16) NOT NULL,
    `product_manufacturer_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`supplier_id`, `product_manufacturer_id`),
    CONSTRAINT `fk_supplier_manufacturer_supplier`
        FOREIGN KEY (`supplier_id`)
        REFERENCES `supplier` (`id`)
        ON DELETE CASCADE,
    KEY `fk_supplier_manufacturer_manufacturer` (`product_manufacturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);

        // Create supplier_category mapping table
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `supplier_category` (
    `supplier_id` BINARY(16) NOT NULL,
    `category_id` BINARY(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`supplier_id`, `category_id`),
    CONSTRAINT `fk_supplier_category_supplier`
        FOREIGN KEY (`supplier_id`)
        REFERENCES `supplier` (`id`)
        ON DELETE CASCADE,
    KEY `fk_supplier_category_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Optional: implement if needed
    }
}
