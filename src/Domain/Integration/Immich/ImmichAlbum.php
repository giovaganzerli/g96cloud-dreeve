<?php

declare(strict_types=1);

namespace App\Domain\Integration\Immich;

final readonly class ImmichAlbum
{
    public function __construct(
        private string $id,
        private string $name,
        private ?string $description = null,
        private int $assetCount = 0,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAssetCount(): int
    {
        return $this->assetCount;
    }
}
