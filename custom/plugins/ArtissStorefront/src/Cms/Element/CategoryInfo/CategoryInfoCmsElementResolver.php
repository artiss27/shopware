<?php declare(strict_types=1);

namespace ArtissStorefront\Cms\Element\CategoryInfo;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;

class CategoryInfoCmsElementResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'category-info';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $context): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $context, ElementDataCollection $result): void
    {
        // Data is taken directly from page.header.navigation.active in template
        // No additional loading required
    }
}
