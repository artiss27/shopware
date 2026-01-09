<?php declare(strict_types=1);

namespace ArtissTheme\Subscriber;

use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NavigationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'addRootCategory',
            ProductPageLoadedEvent::class => 'addRootCategory',
            PageLoadedEvent::class => 'addRootCategory',
        ];
    }

    public function addRootCategory(PageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $context = $event->getSalesChannelContext();
        $navigationRootId = $context->getSalesChannel()->getNavigationCategoryId();
        $request = $event->getRequest();

        $currentCategory = null;

        if ($event instanceof ProductPageLoadedEvent) {
            $product = $page->getProduct();
            if ($product && $product->getSeoCategory()) {
                $currentCategory = $product->getSeoCategory();
            }
        } elseif (method_exists($page, 'getCategory')) {
            $currentCategory = $page->getCategory();
        }

        $rootCategoryId = $this->findRootCategoryId($currentCategory, $navigationRootId);
        
        if ($rootCategoryId && !headers_sent()) {
            $request->attributes->set('rootCategoryId', $rootCategoryId);
        }
    }

    private function findRootCategoryId(?\Shopware\Core\Content\Category\CategoryEntity $currentCategory, string $navigationRootId): ?string
    {
        if (!$currentCategory) {
            return null;
        }

        $path = $currentCategory->getPath();
        if ($path) {
            $pathParts = explode('|', trim($path, '|'));
            $pathParts = array_filter($pathParts, fn($id) => $id !== '' && $id !== $navigationRootId);
            if (!empty($pathParts)) {
                return reset($pathParts);
            }
        }

        if ($currentCategory->getParentId() === $navigationRootId) {
            return $currentCategory->getId();
        }

        return null;
    }
}
