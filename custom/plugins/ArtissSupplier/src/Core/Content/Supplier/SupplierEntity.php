<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SupplierEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    // JSON field for manufacturer IDs
    protected ?array $manufacturerIds = null;

    // JSON field for equipment type IDs
    protected ?array $equipmentTypeIds = null;

    // Custom fields (contacts, commercial terms, additional info, service flags, files)
    protected ?array $customFields = null;

    protected ?ProductCollection $products = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getManufacturerIds(): ?array
    {
        return $this->manufacturerIds;
    }

    public function setManufacturerIds(?array $manufacturerIds): void
    {
        $this->manufacturerIds = $manufacturerIds;
    }

    public function getEquipmentTypeIds(): ?array
    {
        return $this->equipmentTypeIds;
    }

    public function setEquipmentTypeIds(?array $equipmentTypeIds): void
    {
        $this->equipmentTypeIds = $equipmentTypeIds;
    }

    public function getCustomFields(): ?array
    {
        return $this->customFields;
    }

    public function setCustomFields(?array $customFields): void
    {
        $this->customFields = $customFields;
    }

    public function getProducts(): ?ProductCollection
    {
        return $this->products;
    }

    public function setProducts(?ProductCollection $products): void
    {
        $this->products = $products;
    }
}
