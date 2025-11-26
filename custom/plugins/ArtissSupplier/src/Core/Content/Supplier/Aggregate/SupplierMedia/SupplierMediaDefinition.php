<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier\Aggregate\SupplierMedia;

use Artiss\Supplier\Core\Content\Supplier\SupplierDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;

class SupplierMediaDefinition extends MappingEntityDefinition
{
    final public const ENTITY_NAME = 'art_supplier_media';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('supplier_id', 'supplierId', SupplierDefinition::class))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('media_id', 'mediaId', MediaDefinition::class))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            new ManyToOneAssociationField('supplier', 'supplier_id', SupplierDefinition::class, 'id', false),
            new ManyToOneAssociationField('media', 'media_id', MediaDefinition::class, 'id', false),
        ]);
    }
}
