<?php declare(strict_types=1);

namespace ArtissTools\Subscriber;

use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginLifecycleSubscriber implements EventSubscriberInterface
{
    private const CONFIG_PREFIX = 'ArtissTools.config.';

    private const DEFAULTS = [
        'backupPath' => 'artiss-backups',
        'backupRetention' => 5,
        'dbGzipDefault' => true,
        'dbTypeDefault' => 'smart',
        'mediaScopeDefault' => 'all',
        'mediaExcludeThumbnailsDefault' => true,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginPostActivateEvent::class => 'onPluginActivate',
            PluginPostUpdateEvent::class => 'onPluginUpdate',
        ];
    }

    public function onPluginActivate(PluginPostActivateEvent $event): void
    {
        if ($event->getPlugin()->getBaseClass() !== 'ArtissTools\ArtissTools') {
            return;
        }

        $this->ensureDefaults();
    }

    public function onPluginUpdate(PluginPostUpdateEvent $event): void
    {
        if ($event->getPlugin()->getBaseClass() !== 'ArtissTools\ArtissTools') {
            return;
        }

        $this->ensureDefaults();
    }

    /**
     * Set default values if they are not already set
     */
    private function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $key => $defaultValue) {
            $configKey = self::CONFIG_PREFIX . $key;
            $currentValue = $this->systemConfigService->get($configKey);

            if ($currentValue === null) {
                $this->systemConfigService->set($configKey, $defaultValue);
            }
        }
    }
}

