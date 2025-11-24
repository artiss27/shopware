<?php
declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1732456800AddAlternativeManufacturerIds extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732456800;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
ALTER TABLE `art_supplier`
ADD COLUMN `alternative_manufacturer_ids` JSON NULL AFTER `manufacturer_ids`;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        $sql = <<<SQL
ALTER TABLE `art_supplier`
DROP COLUMN IF EXISTS `alternative_manufacturer_ids`;
SQL;
        $connection->executeStatement($sql);
    }
}
