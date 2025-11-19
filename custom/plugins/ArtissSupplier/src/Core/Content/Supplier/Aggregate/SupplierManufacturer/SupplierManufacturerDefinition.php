<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier\Aggregate\SupplierManufacturer;

use Artiss\Supplier\Core\Content\Supplier\SupplierDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;

class SupplierManufacturerDefinition extends MappingEntityDefinition
{
    final public const ENTITY_NAME = 'supplier_manufacturer';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function since(): ?string
    {
        return '1.0.0';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('supplier_id', 'supplierId', SupplierDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('product_manufacturer_id', 'productManufacturerId', ProductManufacturerDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            new ManyToOneAssociationField('supplier', 'supplier_id', SupplierDefinition::class, 'id', false),
            new ManyToOneAssociationField('productManufacturer', 'product_manufacturer_id', ProductManufacturerDefinition::class, 'id', false),
        ]);
    }
}
