<?php declare(strict_types=1);

namespace ArtissStorefront\Subscriber;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StorefrontRenderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    private ?CategoryEntity $currentCategory = null;

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $this->currentCategory = $event->getPage()->getCategory();
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $categories = $event->getPage()->getProduct()->getCategories();
        if ($categories && $categories->count() > 0) {
            $this->currentCategory = $categories->first();
        }
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $rootCategoryId = $context->getSalesChannel()->getNavigationCategoryId();

        $event->setParameter('rootCategoryId', $rootCategoryId);

        if (!$this->currentCategory) {
            return;
        }

        $mainCategoryId = $this->extractMainCategoryId($this->currentCategory, $rootCategoryId);

        if ($mainCategoryId) {
            $event->setParameter('mainCategoryId', $mainCategoryId);
        }
    }

    /**
     * Extracts the main category ID (first level after root) from the current category path.
     * Category path format: |rootId|mainCategoryId|subCategoryId|...|currentId|
     */
    private function extractMainCategoryId(CategoryEntity $category, string $rootCategoryId): ?string
    {
        if ($category->getParentId() === $rootCategoryId) {
            return $category->getId();
        }

        $path = $category->getPath();
        if (!$path) {
            return null;
        }

        $pathIds = array_filter(explode('|', $path));
        $rootPosition = array_search($rootCategoryId, $pathIds);

        if ($rootPosition !== false && isset($pathIds[$rootPosition + 1])) {
            return $pathIds[$rootPosition + 1];
        }

        return null;
    }
}
