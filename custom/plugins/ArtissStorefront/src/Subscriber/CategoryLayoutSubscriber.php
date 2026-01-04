<?php declare(strict_types=1);

namespace ArtissStorefront\Subscriber;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class CategoryLayoutSubscriber implements EventSubscriberInterface
{
    private const HUB_LAYOUT_NAME = 'ARTiss Hub Layout';
    private const LISTING_LAYOUT_NAME = 'ARTiss listing layout with sidebar';

    private EntityRepository $cmsPageRepository;
    private EntityRepository $productRepository;
    private EntityRepository $categoryRepository;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $cmsPageRepository,
        EntityRepository $productRepository,
        EntityRepository $categoryRepository,
        LoggerInterface $logger
    ) {
        $this->cmsPageRepository = $cmsPageRepository;
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => ['onNavigationPageLoaded', 100],
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $category = $page->getCategory();
        $context = $event->getSalesChannelContext();

        if (!$category) {
            return;
        }

        // Skip if category has manually assigned layout
        if ($category->getCmsPageId()) {
            return;
        }

        $hasDirectProducts = $this->categoryHasDirectProducts($category, $context);
        $hasActiveChildren = $this->categoryHasActiveChildren($category, $context);

        // Determine which layout to use
        if (!$hasDirectProducts && $hasActiveChildren) {
            $layoutName = self::HUB_LAYOUT_NAME;
        } else {
            $layoutName = self::LISTING_LAYOUT_NAME;
        }

        $this->assignLayout($page, $layoutName, $context);
    }

    private function categoryHasDirectProducts(CategoryEntity $category, $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('categories.id', $category->getId()));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);

        $result = $this->productRepository->search($criteria, $context->getContext());

        return $result->getTotal() > 0;
    }

    private function categoryHasActiveChildren(CategoryEntity $category, $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $category->getId()));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->setLimit(1);

        $result = $this->categoryRepository->search($criteria, $context->getContext());

        return $result->getTotal() > 0;
    }

    private function assignLayout($page, string $layoutName, $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $layoutName));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);
        $criteria->addAssociation('sections.blocks.slots');

        $result = $this->cmsPageRepository->search($criteria, $context->getContext());

        if ($result->getTotal() > 0) {
            /** @var CmsPageEntity $cmsPage */
            $cmsPage = $result->first();
            $page->setCmsPage($cmsPage);
        }
    }
}
