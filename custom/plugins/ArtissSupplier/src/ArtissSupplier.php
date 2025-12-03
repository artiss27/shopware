<?php declare(strict_types=1);

namespace Artiss\Supplier;

use Artiss\Supplier\Service\CustomFieldInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class ArtissSupplier extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        // Install custom fields manually without service container
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository);
        $customFieldInstaller->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        // Update custom fields on plugin update
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository);
        $customFieldInstaller->install($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove custom fields
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository);
        $customFieldInstaller->uninstall($uninstallContext->getContext());

        // Remove tables
        $connection = $this->container->get('Doctrine\DBAL\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS `art_supplier`');
    }
}
