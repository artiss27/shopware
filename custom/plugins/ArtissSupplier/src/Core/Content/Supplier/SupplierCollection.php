<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                 add(SupplierEntity $entity)
 * @method void                 set(string $key, SupplierEntity $entity)
 * @method SupplierEntity[]     getIterator()
 * @method SupplierEntity[]     getElements()
 * @method SupplierEntity|null  get(string $key)
 * @method SupplierEntity|null  first()
 * @method SupplierEntity|null  last()
 */
class SupplierCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SupplierEntity::class;
    }
}
