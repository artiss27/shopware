<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1700000002AddSupplierIdToProduct extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000002;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
ALTER TABLE `product`
ADD COLUMN `supplier_id` BINARY(16) NULL AFTER `product_manufacturer_id`,
ADD KEY `fk_product_supplier` (`supplier_id`);
SQL;

        try {
            $connection->executeStatement($sql);
        } catch (\Exception $e) {
            // Column might already exist
        }

        // Add foreign key constraint
        $sql = <<<SQL
ALTER TABLE `product`
ADD CONSTRAINT `fk_product_supplier`
    FOREIGN KEY (`supplier_id`)
    REFERENCES `supplier` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
SQL;

        try {
            $connection->executeStatement($sql);
        } catch (\Exception $e) {
            // Constraint might already exist
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Optional: remove column
        // $connection->executeStatement('ALTER TABLE `product` DROP FOREIGN KEY `fk_product_supplier`');
        // $connection->executeStatement('ALTER TABLE `product` DROP COLUMN `supplier_id`');
    }
}
