<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\PriceTemplate;

use Artiss\Supplier\Core\Content\Supplier\SupplierDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class PriceTemplateDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'art_supplier_price_template';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return PriceTemplateCollection::class;
    }

    public function getEntityClass(): string
    {
        return PriceTemplateEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),

            (new FkField('supplier_id', 'supplierId', SupplierDefinition::class))->addFlags(new ApiAware(), new Required()),

            (new StringField('name', 'name', 255))->addFlags(new ApiAware(), new Required()),

            (new JsonField('config', 'config'))->addFlags(new ApiAware()),

            (new FkField('last_import_media_id', 'lastImportMediaId', MediaDefinition::class))->addFlags(new ApiAware()),

            (new DateTimeField('last_import_media_updated_at', 'lastImportMediaUpdatedAt'))->addFlags(new ApiAware()),

            (new LongTextField('normalized_data', 'normalizedData'))->addFlags(new ApiAware()),

            (new JsonField('matched_products', 'matchedProducts'))->addFlags(new ApiAware()),

            (new DateTimeField('applied_at', 'appliedAt'))->addFlags(new ApiAware()),

            (new FkField('applied_by_user_id', 'appliedByUserId', UserDefinition::class))->addFlags(new ApiAware()),

            // Associations
            (new ManyToOneAssociationField('supplier', 'supplier_id', SupplierDefinition::class, 'id', false))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('lastImportMedia', 'last_import_media_id', MediaDefinition::class, 'id', false))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField('appliedByUser', 'applied_by_user_id', UserDefinition::class, 'id', false))->addFlags(new ApiAware()),
        ]);
    }
}
