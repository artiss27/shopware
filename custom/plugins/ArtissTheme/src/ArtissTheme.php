<?php declare(strict_types=1);

namespace ArtissTheme;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class ArtissTheme extends Plugin implements ThemeInterface
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $configPath = $this->getPath() . '/Resources/config';
        
        if (file_exists($configPath . '/services.xml')) {
            $loader = new XmlFileLoader($container, new FileLocator($configPath));
            $loader->load('services.xml');
        }
    }
}