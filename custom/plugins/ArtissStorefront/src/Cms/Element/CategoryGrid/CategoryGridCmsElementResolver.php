<?php declare(strict_types=1);

namespace ArtissStorefront\Cms\Element\CategoryGrid;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Psr\Log\LoggerInterface;

class CategoryGridCmsElementResolver extends AbstractCmsElementResolver
{
    private EntityRepository $categoryRepository;
    private EntityRepository $productRepository;
    private ?LoggerInterface $logger;

    public function __construct(
        EntityRepository $categoryRepository,
        EntityRepository $productRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function getType(): string
    {
        return 'category-grid';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $context): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $context, ElementDataCollection $result): void
    {
        $salesChannelContext = $context->getSalesChannelContext();
        $categoryId = null;

        // Get category ID from EntityResolverContext (when CMS is rendered for a category)
        if ($context instanceof EntityResolverContext) {
            $entity = $context->getEntity();
            
            // Handle both CategoryEntity and SalesChannelCategoryEntity
            if ($entity instanceof SalesChannelCategoryEntity) {
                $categoryId = $entity->getId();
            } elseif ($entity instanceof CategoryEntity) {
                $categoryId = $entity->getId();
            }
        }

        // Fallback to request attributes (route parameters)
        if (!$categoryId && $context->getRequest()) {
            $request = $context->getRequest();

            // Try route attributes first (most common case for navigation pages)
            $categoryId = $request->attributes->get('navigationId');
            
            // Try to get from route parameters
            if (!$categoryId) {
                $routeParams = $request->attributes->get('_route_params', []);
                $categoryId = $routeParams['navigationId'] ?? null;
            }
            
            // Try query parameter
            if (!$categoryId) {
                $categoryId = $request->query->get('navigationId');
            }
            
            // Try request parameter
            if (!$categoryId) {
                $categoryId = $request->get('navigationId');
            }
        }

        if (!$categoryId) {
            $slot->setData(new ArrayStruct(['categories' => []]));
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $categoryId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addAssociation('media');

        $children = $this->categoryRepository->search($criteria, $salesChannelContext->getContext());

        // Convert to CategoryCollection and sort by position (same as navigation menu)
        $categoryCollection = new CategoryCollection($children->getEntities());
        $categoryCollection->sortByPosition();

        $categories = [];
        foreach ($categoryCollection as $child) {
            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('categories.id', $child->getId()));
            $productCriteria->addFilter(new EqualsFilter('active', true));
            $productCriteria->setLimit(1);
            $productCriteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

            $productResult = $this->productRepository->search($productCriteria, $salesChannelContext->getContext());
            $productCount = $productResult->getTotal();

            $child->addExtension('productCount', new ArrayStruct(['count' => $productCount]));
            $categories[] = $child;
        }

        $slot->setData(new ArrayStruct([
            'categories' => array_values($categories)
        ]));
    }
}
