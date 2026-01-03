<?php declare(strict_types=1);

namespace ArtissStorefront\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingResultEvent::class => 'removePropertyFilters',
            ProductSearchResultEvent::class => 'removePropertyFilters',
        ];
    }

    public function removePropertyFilters($event): void
    {
        $request = $event->getRequest();

        // Работаем ТОЛЬКО если есть параметр manufacturer (страница бренда)
        if (!$request->query->has('manufacturer') && !$request->request->has('manufacturer')) {
            return;
        }

        $result = $event->getResult();
        $aggregations = $result->getAggregations();

        // Удаляем все property-related aggregations
        foreach ($aggregations->getKeys() as $key) {
            if (str_starts_with($key, 'properties') || str_starts_with($key, 'options')) {
                $aggregations->remove($key);
            }
        }
    }
}
