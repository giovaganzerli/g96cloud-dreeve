<?php

declare(strict_types=1);

namespace App\Domain\Integration\Immich;

final readonly class ImmichAsset
{
    public function __construct(
        private string $id,
        private string $type,
        private ?string $originalPath = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOriginalPath(): ?string
    {
        return $this->originalPath;
    }

    public function isImage(): bool
    {
        return 'IMAGE' === strtoupper($this->type) || 'VIDEO' === strtoupper($this->type);
    }
}
