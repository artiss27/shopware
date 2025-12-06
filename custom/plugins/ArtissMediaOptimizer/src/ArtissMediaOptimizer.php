<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer;

use Artiss\MediaOptimizer\Service\CustomFieldInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class ArtissMediaOptimizer extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->installCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->installCustomFields($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->uninstallCustomFields($uninstallContext->getContext());
    }

    private function installCustomFields(\Shopware\Core\Framework\Context $context): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $installer = new CustomFieldInstaller($customFieldSetRepository);
        $installer->install($context);
    }

    private function uninstallCustomFields(\Shopware\Core\Framework\Context $context): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $installer = new CustomFieldInstaller($customFieldSetRepository);
        $installer->uninstall($context);
    }
}
