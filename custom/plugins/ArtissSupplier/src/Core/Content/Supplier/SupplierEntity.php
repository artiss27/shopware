<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SupplierEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;
    protected ?string $code = null;
    protected bool $active = true;
    protected ?int $sort = null;
    protected ?int $bitrixId = null;
    protected ?ProductCollection $products = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getSort(): ?int
    {
        return $this->sort;
    }

    public function setSort(?int $sort): void
    {
        $this->sort = $sort;
    }

    public function getBitrixId(): ?int
    {
        return $this->bitrixId;
    }

    public function setBitrixId(?int $bitrixId): void
    {
        $this->bitrixId = $bitrixId;
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
