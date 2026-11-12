<?php

declare(strict_types=1);

namespace App\Domain\Integration\OpenStreetMap;

final readonly class OpenStreetMapWay
{
    /**
     * @param list<array{float, float}> $geometry the lat/lng pairs of the way
     * @param array<string, string>     $tags
     */
    public function __construct(
        private int $id,
        private array $geometry,
        private array $tags,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return list<array{float, float}>
     */
    public function getGeometry(): array
    {
        return $this->geometry;
    }

    /**
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTag(string $name): ?string
    {
        $value = $this->tags[$name] ?? null;

        return null !== $value ? strtolower(trim($value)) : null;
    }
}
