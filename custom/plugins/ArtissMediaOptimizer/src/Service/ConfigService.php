<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    private const CONFIG_PREFIX = 'ArtissMediaOptimizer.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    public function getWebpQuality(): int
    {
        $quality = (int) $this->get('webpQuality', 85);
        return max(0, min(100, $quality));
    }

    public function isResizeEnabled(): bool
    {
        return (bool) $this->get('enableResize', true);
    }

    public function getMaxWidth(): int
    {
        return (int) $this->get('maxWidth', 1600);
    }

    public function getMaxHeight(): int
    {
        return (int) $this->get('maxHeight', 1600);
    }

    public function shouldKeepOriginal(): bool
    {
        return (bool) $this->get('keepOriginal', true);
    }

    public function getOriginalStorageDir(): string
    {
        return (string) $this->get('originalStorageDir', 'artiss_media/original');
    }

    public function getOnConvertError(): string
    {
        $value = (string) $this->get('onConvertError', 'fail');
        return in_array($value, ['fail', 'fallback_original'], true) ? $value : 'fail';
    }

    public function shouldFallbackOnError(): bool
    {
        return $this->getOnConvertError() === 'fallback_original';
    }

    public function isCropperEnabled(): bool
    {
        return (bool) $this->get('enableCropper', false);
    }

    public function getProductImageAspectRatio(): string
    {
        $ratio = (string) $this->get('productImageAspectRatio', 'free');
        $validRatios = ['free', '1:1', '4:3', '3:4', '16:9', 'custom'];
        return in_array($ratio, $validRatios, true) ? $ratio : 'free';
    }

    public function getCustomAspectRatioWidth(): int
    {
        return max(1, (int) $this->get('customAspectRatioWidth', 1));
    }

    public function getCustomAspectRatioHeight(): int
    {
        return max(1, (int) $this->get('customAspectRatioHeight', 1));
    }

    /**
     * Get aspect ratio as a float (width / height).
     * Returns null for 'free' (no restriction).
     */
    public function getAspectRatioValue(): ?float
    {
        $ratio = $this->getProductImageAspectRatio();

        return match ($ratio) {
            'free' => null,
            '1:1' => 1.0,
            '4:3' => 4 / 3,
            '3:4' => 3 / 4,
            '16:9' => 16 / 9,
            'custom' => $this->getCustomAspectRatioWidth() / $this->getCustomAspectRatioHeight(),
            default => null,
        };
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->systemConfigService->get(self::CONFIG_PREFIX . $key) ?? $default;
    }
}
