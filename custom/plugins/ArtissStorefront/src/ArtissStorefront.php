<?php declare(strict_types=1);

namespace ArtissStorefront;

use ArtissStorefront\Service\CustomFieldInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class ArtissStorefront extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');
    }

    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        $routes->import(__DIR__ . '/Resources/config/routes.xml');
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository, $customFieldRepository);
        $customFieldInstaller->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository, $customFieldRepository);
        $customFieldInstaller->install($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');
        $customFieldInstaller = new CustomFieldInstaller($customFieldSetRepository, $customFieldRepository);
        $customFieldInstaller->uninstall($uninstallContext->getContext());
    }
}
