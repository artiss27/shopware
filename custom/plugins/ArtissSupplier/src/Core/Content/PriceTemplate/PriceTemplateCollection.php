<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\PriceTemplate;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<PriceTemplateEntity>
 */
class PriceTemplateCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PriceTemplateEntity::class;
    }
}
