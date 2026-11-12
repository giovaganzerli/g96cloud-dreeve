<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Collection\Statistics\CollectionStatistic;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Domain\Integration\AI\SupportsAITooling;
use App\Infrastructure\Eventing\RecordsEvents;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'Collection')]
#[ORM\Index(name: 'Collection_createdOnIndex', columns: ['createdOn'])]
final class Collection implements SupportsAITooling
{
    use RecordsEvents;

    /**
     * @param array<string, string> $statisticOverrides
     * @param string[]              $statisticOrder
     */
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private readonly CollectionId $collectionId,
        #[ORM\Column(type: 'datetime_immutable')]
        private readonly SerializableDateTime $createdOn,
        #[ORM\Column(type: 'string')]
        private readonly string $name,
        #[ORM\Column(type: 'text', nullable: true)]
        private readonly ?string $description,
        #[ORM\Column(type: 'string', nullable: true)]
        private readonly ?string $localImagePath,
        #[ORM\Column(type: 'json')]
        private readonly ActivityIds $activityIds,
        #[ORM\Column(type: 'string', nullable: true)]
        private readonly ?string $immichAlbumId,
        #[ORM\Column(type: 'json')]
        private readonly array $statisticOverrides,
        #[ORM\Column(type: 'json')]
        private readonly CollectionStatistics $customStatistics,
        #[ORM\Column(type: 'json')]
        private readonly array $statisticOrder,
    ) {
    }

    /**
     * @param array<string, string> $statisticOverrides
     * @param string[]              $statisticOrder
     */
    public static function create(
        CollectionId $collectionId,
        SerializableDateTime $createdOn,
        string $name,
        ?string $description = null,
        ?string $localImagePath = null,
        ?ActivityIds $activityIds = null,
        ?string $immichAlbumId = null,
        ?array $statisticOverrides = null,
        ?CollectionStatistics $customStatistics = null,
        ?array $statisticOrder = null,
    ): self {
        $collection = new self(
            collectionId: $collectionId,
            createdOn: $createdOn,
            name: $name,
            description: $description,
            localImagePath: $localImagePath,
            activityIds: $activityIds ?? ActivityIds::empty(),
            immichAlbumId: $immichAlbumId,
            statisticOverrides: $statisticOverrides ?? [],
            customStatistics: $customStatistics ?? CollectionStatistics::empty(),
            statisticOrder: $statisticOrder ?? [],
        );
        $collection->recordThat(new CollectionWasAdded());

        return $collection;
    }

    /**
     * @param array<string, string> $statisticOverrides
     * @param string[]              $statisticOrder
     */
    public static function fromState(
        CollectionId $collectionId,
        SerializableDateTime $createdOn,
        string $name,
        ?string $description,
        ?string $localImagePath,
        ActivityIds $activityIds,
        ?string $immichAlbumId,
        array $statisticOverrides,
        CollectionStatistics $customStatistics,
        array $statisticOrder,
    ): self {
        return new self(
            collectionId: $collectionId,
            createdOn: $createdOn,
            name: $name,
            description: $description,
            localImagePath: $localImagePath,
            activityIds: $activityIds,
            immichAlbumId: $immichAlbumId,
            statisticOverrides: $statisticOverrides,
            customStatistics: $customStatistics,
            statisticOrder: $statisticOrder,
        );
    }

    public function getId(): CollectionId
    {
        return $this->collectionId;
    }

    public function getCreatedOn(): SerializableDateTime
    {
        return $this->createdOn;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLocalImagePath(): ?string
    {
        return $this->localImagePath;
    }

    public function getImageUrl(): ?string
    {
        if (null === $this->localImagePath) {
            return null;
        }

        return str_starts_with($this->localImagePath, '/')
            ? $this->localImagePath
            : '/'.$this->localImagePath;
    }

    public function getActivityIds(): ActivityIds
    {
        return $this->activityIds;
    }

    public function getImmichAlbumId(): ?string
    {
        return $this->immichAlbumId;
    }

    /**
     * @return array<string, string>
     */
    public function getStatisticOverrides(): array
    {
        return $this->statisticOverrides;
    }

    public function getCustomStatistics(): CollectionStatistics
    {
        return $this->customStatistics;
    }

    /**
     * @return string[]
     */
    public function getStatisticOrder(): array
    {
        return $this->statisticOrder;
    }

    public function withName(string $name): self
    {
        if ($name === $this->name) {
            return $this;
        }

        return $this->with(['name' => $name]);
    }

    public function withDescription(?string $description): self
    {
        if ($description === $this->description) {
            return $this;
        }

        return $this->with(['description' => $description]);
    }

    public function withLocalImagePath(?string $localImagePath): self
    {
        if ($localImagePath === $this->localImagePath) {
            return $this;
        }

        return $this->with(['localImagePath' => $localImagePath]);
    }

    public function withActivityIds(ActivityIds $activityIds): self
    {
        if ($activityIds->toArray() === $this->activityIds->toArray()) {
            return $this;
        }

        return $this->with(['activityIds' => $activityIds]);
    }

    public function withImmichAlbumId(?string $immichAlbumId): self
    {
        if ($immichAlbumId === $this->immichAlbumId) {
            return $this;
        }

        return $this->with(['immichAlbumId' => $immichAlbumId]);
    }

    /**
     * @param array<string, string> $statisticOverrides
     */
    public function withStatisticOverrides(array $statisticOverrides): self
    {
        return $this->with(['statisticOverrides' => $statisticOverrides]);
    }

    public function withCustomStatistics(CollectionStatistics $customStatistics): self
    {
        return $this->with(['customStatistics' => $customStatistics]);
    }

    /**
     * @param string[] $statisticOrder
     */
    public function withStatisticOrder(array $statisticOrder): self
    {
        return $this->with(['statisticOrder' => array_values($statisticOrder)]);
    }

    public function delete(): void
    {
        $this->recordThat(new CollectionWasDeleted($this->collectionId));
    }

    /**
     * @return string[]
     */
    public function getSearchables(): array
    {
        return array_filter([
            $this->name,
            $this->description,
        ]);
    }

    /**
     * @return array<string, string|int>
     */
    public function getFilterables(): array
    {
        return array_filter([
            'numberOfActivities' => $this->activityIds->count(),
            'createdOn' => $this->createdOn->getTimestamp() * 1000,
        ]);
    }

    /**
     * @return array<string, string|int|float>
     */
    public function getSortables(): array
    {
        return array_filter([
            'name' => $this->name,
            'created-on' => $this->createdOn->getTimestamp(),
            'number-of-activities' => $this->activityIds->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForAITooling(): array
    {
        return [
            'id' => $this->getId()->toUnprefixedString(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'activityIds' => array_map(
                static fn (ActivityId $activityId): string => $activityId->toUnprefixedString(),
                $this->activityIds->toArray()
            ),
            'customStatistics' => array_map(
                static fn (CollectionStatistic $statistic): array => $statistic->jsonSerialize(),
                $this->customStatistics->toArray()
            ),
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        $clone = clone ($this, $changes);
        $clone->recordOnlyOnce(new CollectionWasUpdated($this->collectionId));

        return $clone;
    }
}
