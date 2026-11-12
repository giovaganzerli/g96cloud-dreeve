<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Collection\Collection;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\Statistics\CollectionStatistic;
use App\Domain\Collection\Statistics\CollectionStatisticCategory;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class CollectionBuilder
{
    private CollectionId $collectionId;
    private SerializableDateTime $createdOn;
    private string $name;
    private ?string $description = null;
    private ?string $localImagePath = null;
    private ActivityIds $activityIds;
    private ?string $immichAlbumId = null;
    /** @var array<string, string> */
    private array $statisticOverrides = [];
    private CollectionStatistics $customStatistics;
    /** @var string[] */
    private array $statisticOrder = [];

    private function __construct()
    {
        $this->collectionId = CollectionId::fromUnprefixed('1');
        $this->createdOn = SerializableDateTime::fromString('2025-01-01 10:00:00');
        $this->name = 'My collection';
        $this->activityIds = ActivityIds::empty();
        $this->customStatistics = CollectionStatistics::empty();
    }

    public static function fromDefaults(): self
    {
        return new self();
    }

    public function build(): Collection
    {
        return Collection::fromState(
            collectionId: $this->collectionId,
            createdOn: $this->createdOn,
            name: $this->name,
            description: $this->description,
            localImagePath: $this->localImagePath,
            activityIds: $this->activityIds,
            immichAlbumId: $this->immichAlbumId,
            statisticOverrides: $this->statisticOverrides,
            customStatistics: $this->customStatistics,
            statisticOrder: $this->statisticOrder,
        );
    }

    public function withCollectionId(CollectionId $collectionId): self
    {
        $this->collectionId = $collectionId;

        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function withDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withLocalImagePath(?string $localImagePath): self
    {
        $this->localImagePath = $localImagePath;

        return $this;
    }

    public function withActivityIds(ActivityIds $activityIds): self
    {
        $this->activityIds = $activityIds;

        return $this;
    }

    public function withActivityId(ActivityId $activityId): self
    {
        $this->activityIds = ActivityIds::fromArray([...$this->activityIds->toArray(), $activityId]);

        return $this;
    }

    public function withImmichAlbumId(?string $immichAlbumId): self
    {
        $this->immichAlbumId = $immichAlbumId;

        return $this;
    }

    /**
     * @param array<string, string> $statisticOverrides
     */
    public function withStatisticOverrides(array $statisticOverrides): self
    {
        $this->statisticOverrides = $statisticOverrides;

        return $this;
    }

    public function withCustomStatistics(CollectionStatistics $customStatistics): self
    {
        $this->customStatistics = $customStatistics;

        return $this;
    }

    public function withCustomStatistic(
        string $id,
        string $label,
        string $value,
        ?string $unit = null,
        ?CollectionStatisticCategory $category = null,
    ): self {
        $this->customStatistics = CollectionStatistics::fromArray([
            ...$this->customStatistics->toArray(),
            CollectionStatistic::custom(
                id: $id,
                label: $label,
                value: $value,
                unit: $unit,
                category: $category,
            ),
        ]);

        return $this;
    }

    /**
     * @param string[] $statisticOrder
     */
    public function withStatisticOrder(array $statisticOrder): self
    {
        $this->statisticOrder = $statisticOrder;

        return $this;
    }
}
