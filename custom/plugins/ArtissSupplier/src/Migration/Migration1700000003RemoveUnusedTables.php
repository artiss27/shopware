<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1700000003RemoveUnusedTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000003;
    }

    public function update(Connection $connection): void
    {
        // Drop unused ManyToMany tables
        $connection->executeStatement('DROP TABLE IF EXISTS `supplier_manufacturer`');
        $connection->executeStatement('DROP TABLE IF EXISTS `supplier_category`');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
