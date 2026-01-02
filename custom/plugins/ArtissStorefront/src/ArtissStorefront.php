<?php declare(strict_types=1);

namespace ArtissStorefront;

use Shopware\Core\Framework\Plugin;
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
}
