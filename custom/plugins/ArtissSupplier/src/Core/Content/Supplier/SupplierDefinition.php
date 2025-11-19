<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SupplierDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'supplier';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return SupplierCollection::class;
    }

    public function getEntityClass(): string
    {
        return SupplierEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            (new StringField('name', 'name', 255))->addFlags(
                new ApiAware(),
                new Required(),
                new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)
            ),

            (new StringField('code', 'code', 255))->addFlags(new ApiAware()),

            (new BoolField('active', 'active'))->addFlags(new ApiAware()),

            (new IntField('sort', 'sort'))->addFlags(new ApiAware()),

            (new IntField('bitrix_id', 'bitrixId'))->addFlags(new ApiAware()),

            // Products that belong to this supplier
            new OneToManyAssociationField('products', ProductDefinition::class, 'supplier_id', 'id'),
        ]);
    }
}
