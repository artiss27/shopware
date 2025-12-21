<?php
declare(strict_types=1);

namespace ArtissTools\Message;

class UpdateMediaHashesMessage
{
    private int $batchSize;
    private int $offset;
    private ?string $folderEntity;

    public function __construct(
        int $batchSize = 100,
        int $offset = 0,
        ?string $folderEntity = null
    ) {
        $this->batchSize = $batchSize;
        $this->offset = $offset;
        $this->folderEntity = $folderEntity;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getFolderEntity(): ?string
    {
        return $this->folderEntity;
    }
}
