<?php declare(strict_types=1);

namespace ArtissStorefront\Cms\Element\CategoryH1;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CategoryH1CmsElementResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'category-h1';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $context): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $context, ElementDataCollection $result): void
    {
        $category = null;
        
        // Get category from EntityResolverContext
        if ($context instanceof EntityResolverContext) {
            $entity = $context->getEntity();
            
            if ($entity instanceof SalesChannelCategoryEntity) {
                $category = $entity;
            } elseif ($entity instanceof CategoryEntity) {
                $category = $entity;
            }
        }

        $h1 = null;
        
        if ($category) {
            // Get H1 from custom field or fallback to category name
            $customFields = $category->getCustomFields();
            
            if ($customFields && isset($customFields['category_h1_tag']) && !empty($customFields['category_h1_tag'])) {
                $h1 = $customFields['category_h1_tag'];
            } else {
                // Fallback to translated name
                $translated = $category->getTranslated();
                $h1 = $translated['name'] ?? $category->getName();
            }
        }

        $slot->setData(new ArrayStruct(['h1' => $h1]));
    }
}
