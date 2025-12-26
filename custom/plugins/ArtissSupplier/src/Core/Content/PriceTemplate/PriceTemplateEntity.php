<?php declare(strict_types=1);

namespace Artiss\Supplier\Core\Content\PriceTemplate;

use Artiss\Supplier\Core\Content\Supplier\SupplierEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\User\UserEntity;

class PriceTemplateEntity extends Entity
{
    use EntityIdTrait;

    protected string $supplierId;

    protected string $name;

    protected ?array $config = null;

    protected ?string $lastImportMediaId = null;

    protected ?\DateTimeInterface $lastImportMediaUpdatedAt = null;

    protected ?string $normalizedData = null;

    protected ?array $matchedProducts = null;

    protected ?\DateTimeInterface $appliedAt = null;

    protected ?string $appliedByUserId = null;

    // Associations
    protected ?SupplierEntity $supplier = null;

    protected ?MediaEntity $lastImportMedia = null;

    protected ?UserEntity $appliedByUser = null;

    public function getSupplierId(): string
    {
        return $this->supplierId;
    }

    public function setSupplierId(string $supplierId): void
    {
        $this->supplierId = $supplierId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): void
    {
        $this->config = $config;
    }

    public function getLastImportMediaId(): ?string
    {
        return $this->lastImportMediaId;
    }

    public function setLastImportMediaId(?string $lastImportMediaId): void
    {
        $this->lastImportMediaId = $lastImportMediaId;
    }

    public function getLastImportMediaUpdatedAt(): ?\DateTimeInterface
    {
        return $this->lastImportMediaUpdatedAt;
    }

    public function setLastImportMediaUpdatedAt(?\DateTimeInterface $lastImportMediaUpdatedAt): void
    {
        $this->lastImportMediaUpdatedAt = $lastImportMediaUpdatedAt;
    }

    public function getNormalizedData(): ?string
    {
        return $this->normalizedData;
    }

    public function setNormalizedData(?string $normalizedData): void
    {
        $this->normalizedData = $normalizedData;
    }

    public function getMatchedProducts(): ?array
    {
        return $this->matchedProducts;
    }

    public function setMatchedProducts(?array $matchedProducts): void
    {
        $this->matchedProducts = $matchedProducts;
    }

    public function getAppliedAt(): ?\DateTimeInterface
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeInterface $appliedAt): void
    {
        $this->appliedAt = $appliedAt;
    }

    public function getAppliedByUserId(): ?string
    {
        return $this->appliedByUserId;
    }

    public function setAppliedByUserId(?string $appliedByUserId): void
    {
        $this->appliedByUserId = $appliedByUserId;
    }

    public function getSupplier(): ?SupplierEntity
    {
        return $this->supplier;
    }

    public function setSupplier(?SupplierEntity $supplier): void
    {
        $this->supplier = $supplier;
    }

    public function getLastImportMedia(): ?MediaEntity
    {
        return $this->lastImportMedia;
    }

    public function setLastImportMedia(?MediaEntity $lastImportMedia): void
    {
        $this->lastImportMedia = $lastImportMedia;
    }

    public function getAppliedByUser(): ?UserEntity
    {
        return $this->appliedByUser;
    }

    public function setAppliedByUser(?UserEntity $appliedByUser): void
    {
        $this->appliedByUser = $appliedByUser;
    }
}
