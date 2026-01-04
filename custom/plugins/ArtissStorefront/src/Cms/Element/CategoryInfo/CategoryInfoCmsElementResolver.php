<?php declare(strict_types=1);

namespace ArtissStorefront\Cms\Element\CategoryInfo;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;

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

        $data = new ArrayStruct();
        
        // If we have category, get description and media from it
        if ($category) {
            // Get translated description
            $description = $category->getTranslation('description') ?? $category->getDescription();
            
            // Get media
            $media = $category->getMedia();
            
            $data->assign([
                'description' => $description,
                'media' => $media,
            ]);
        }

        // Process mapped fields from config (if configured)
        $config = $slot->getFieldConfig();

        // Process description field mapping
        $descriptionConfig = $config->get('description');
        if ($descriptionConfig && $descriptionConfig->isMapped() && $context instanceof EntityResolverContext) {
            $mappedDescription = $this->resolveEntityValueToString(
                $context->getEntity(),
                $descriptionConfig->getStringValue(),
                $context
            );
            if ($mappedDescription) {
                $data->assign(['description' => $mappedDescription]);
            }
        } elseif ($descriptionConfig && $descriptionConfig->isStatic()) {
            $data->assign(['description' => $descriptionConfig->getStringValue()]);
        }

        // Process media field mapping
        $mediaConfig = $config->get('media');
        if ($mediaConfig && $mediaConfig->isMapped() && $context instanceof EntityResolverContext) {
            $mappedMedia = $this->resolveEntityValue(
                $context->getEntity(),
                $mediaConfig->getStringValue()
            );
            if ($mappedMedia) {
                $data->assign(['media' => $mappedMedia]);
            }
        }

        $slot->setData($data);
    }
}
