<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Product;

use Artiss\Supplier\Core\Content\Supplier\SupplierDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ProductSupplierExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new FkField('supplier_id', 'supplierId', SupplierDefinition::class))->addFlags(new ApiAware())
        );

        $collection->add(
            (new ManyToOneAssociationField('supplier', 'supplier_id', SupplierDefinition::class, 'id', false))->addFlags(new ApiAware())
        );
    }

    public function getDefinitionClass(): string
    {
        return ProductDefinition::class;
    }

    public function getEntityName(): string
    {
        return 'product';
    }
}
