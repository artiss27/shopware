<?php declare(strict_types=1);

namespace Artiss\Supplier;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class ArtissSupplier extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove tables if needed
        $connection = $this->container->get('Doctrine\DBAL\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS `supplier_manufacturer`');
        $connection->executeStatement('DROP TABLE IF EXISTS `supplier_category`');
        $connection->executeStatement('DROP TABLE IF EXISTS `supplier`');
    }
}
