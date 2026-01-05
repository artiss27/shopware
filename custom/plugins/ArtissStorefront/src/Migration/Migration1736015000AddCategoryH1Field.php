<?php declare(strict_types=1);

namespace ArtissStorefront\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1736015000AddCategoryH1Field extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1736015000;
    }

    public function update(Connection $connection): void
    {
        // Custom fields are managed by CustomFieldInstaller service
        // This migration serves as a version marker
        // The actual custom field installation happens in plugin install/update lifecycle
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes needed
    }
}
