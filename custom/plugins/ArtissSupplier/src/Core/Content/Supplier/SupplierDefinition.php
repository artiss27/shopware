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
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SupplierDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'art_supplier';

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

            // JSON field for manufacturer IDs (for filtering)
            (new JsonField('manufacturer_ids', 'manufacturerIds'))->addFlags(new ApiAware()),

            // JSON field for alternative manufacturer IDs (for filtering)
            (new JsonField('alternative_manufacturer_ids', 'alternativeManufacturerIds'))->addFlags(new ApiAware()),

            // JSON field for equipment type IDs (for filtering)
            (new JsonField('equipment_type_ids', 'equipmentTypeIds'))->addFlags(new ApiAware()),

            // Custom fields for all other properties (contacts, commercial terms, etc.)
            (new CustomFields())->addFlags(new ApiAware()),

            // Products that belong to this supplier
            new OneToManyAssociationField('products', ProductDefinition::class, 'supplier_id', 'id'),
        ]);
    }
}
