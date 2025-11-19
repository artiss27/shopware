<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\Supplier;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SupplierEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    // Contact information
    protected ?string $city = null;
    protected ?string $contacts = null;
    protected ?string $email = null;
    protected ?string $website = null;

    // Business terms
    protected ?string $discountOnline = null;
    protected ?string $discountOpt = null;
    protected ?string $margin = null;

    // Additional info
    protected ?string $note = null;
    protected ?string $details = null;

    // Custom fields (code, bitrix_id, etc.)
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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getContacts(): ?string
    {
        return $this->contacts;
    }

    public function setContacts(?string $contacts): void
    {
        $this->contacts = $contacts;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
    }

    public function getDiscountOnline(): ?string
    {
        return $this->discountOnline;
    }

    public function setDiscountOnline(?string $discountOnline): void
    {
        $this->discountOnline = $discountOnline;
    }

    public function getDiscountOpt(): ?string
    {
        return $this->discountOpt;
    }

    public function setDiscountOpt(?string $discountOpt): void
    {
        $this->discountOpt = $discountOpt;
    }

    public function getMargin(): ?string
    {
        return $this->margin;
    }

    public function setMargin(?string $margin): void
    {
        $this->margin = $margin;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): void
    {
        $this->details = $details;
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
