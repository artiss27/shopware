<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CustomFields;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
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

            // Contact information
            (new StringField('city', 'city', 255))->addFlags(new ApiAware()),
            (new StringField('contacts', 'contacts', 1000))->addFlags(new ApiAware()),
            (new StringField('email', 'email', 255))->addFlags(new ApiAware()),
            (new StringField('website', 'website', 255))->addFlags(new ApiAware()),

            // Business terms
            (new StringField('discount_online', 'discountOnline', 50))->addFlags(new ApiAware()),
            (new StringField('discount_opt', 'discountOpt', 50))->addFlags(new ApiAware()),
            (new StringField('margin', 'margin', 50))->addFlags(new ApiAware()),

            // Additional info
            (new StringField('note', 'note', 1000))->addFlags(new ApiAware()),
            (new StringField('details', 'details', 5000))->addFlags(new ApiAware()),

            // Custom fields for code, bitrix_id and other metadata
            (new CustomFields())->addFlags(new ApiAware()),

            // Products that belong to this supplier
            new OneToManyAssociationField('products', ProductDefinition::class, 'supplier_id', 'id'),
        ]);
    }
}
