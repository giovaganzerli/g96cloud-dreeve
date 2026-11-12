<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\SportType\SportType;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * The aggregated data of the activities in a collection, used to describe it in a list.
 */
final readonly class CollectionSummary
{
    /**
     * @param string[] $countryCodes
     * @param string[] $sportTypes
     * @param string[] $deviceIds
     */
    public function __construct(
        private Collection $collection,
        private int $numberOfActivities,
        private Kilometer $totalDistance,
        private Meter $totalElevation,
        private int $movingTimeInSeconds,
        private int $elapsedTimeInSeconds,
        private ?SerializableDateTime $startDate,
        private ?SerializableDateTime $endDate,
        private array $countryCodes,
        private array $sportTypes,
        private array $deviceIds,
    ) {
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function getNumberOfActivities(): int
    {
        return $this->numberOfActivities;
    }

    public function getTotalDistance(): Kilometer
    {
        return $this->totalDistance;
    }

    public function getTotalElevation(): Meter
    {
        return $this->totalElevation;
    }

    public function getMovingTimeInSeconds(): int
    {
        return $this->movingTimeInSeconds;
    }

    public function getElapsedTimeInSeconds(): int
    {
        return $this->elapsedTimeInSeconds;
    }

    public function getStartDate(): ?SerializableDateTime
    {
        return $this->startDate;
    }

    public function getEndDate(): ?SerializableDateTime
    {
        return $this->endDate;
    }

    /**
     * @return string[]
     */
    public function getCountryCodes(): array
    {
        return $this->countryCodes;
    }

    /**
     * @return string[]
     */
    public function getSportTypes(): array
    {
        return $this->sportTypes;
    }

    /**
     * @return string[]
     */
    public function getDeviceIds(): array
    {
        return $this->deviceIds;
    }

    /**
     * The number of calendar days the collection spans.
     */
    public function getNumberOfDays(): int
    {
        if (!$this->startDate instanceof SerializableDateTime || !$this->endDate instanceof SerializableDateTime) {
            return 0;
        }

        return (int) $this->startDate->diff($this->endDate)->days + 1;
    }

    public function hasDescription(): bool
    {
        return null !== $this->collection->getDescription() && '' !== trim($this->collection->getDescription());
    }

    public function hasGallery(): bool
    {
        return null !== $this->collection->getImmichAlbumId();
    }

    public function hasImage(): bool
    {
        return null !== $this->collection->getLocalImagePath();
    }

    /**
     * @return string[]
     */
    public function getSearchables(): array
    {
        $searchables = [];
        foreach ([$this->collection->getName(), $this->collection->getDescription()] as $searchable) {
            if (null !== $searchable && '' !== trim($searchable)) {
                $searchables[] = mb_strtolower($searchable);
            }
        }

        return $searchables;
    }

    /**
     * @return array<string, string|int|string[]>
     */
    public function getFilterables(UnitSystem $unitSystem): array
    {
        return array_filter([
            'sportType' => $this->sportTypes,
            'start-date' => $this->startDate?->getTimestamp() * 1000, // JS timestamp is in milliseconds,
            'countryCode' => $this->countryCodes,
            'device' => $this->deviceIds,
            'distance' => (int) round($this->totalDistance->toUnitSystem($unitSystem)->toFloat() * 10), // We don't want to filter on float values, but integers instead.
            'elevation' => (int) round($this->totalElevation->toUnitSystem($unitSystem)->toFloat() * 10),
            'hasDescription' => $this->hasDescription() ? 'with' : 'without',
            'hasGallery' => $this->hasGallery() ? 'with' : 'without',
            'hasImage' => $this->hasImage() ? 'with' : 'without',
        ]);
    }

    /**
     * @return array<string, string|int|float>
     */
    public function getSortables(): array
    {
        return array_filter([
            'name' => $this->collection->getName(),
            'start-date' => $this->startDate?->getTimestamp(),
            'number-of-activities' => $this->numberOfActivities,
            'distance' => (int) ($this->totalDistance->toFloat() * 1000),
            'elevation' => (int) ($this->totalElevation->toFloat() * 1000),
            'moving-time' => $this->movingTimeInSeconds,
        ]);
    }

    /**
     * @return SportType[]
     */
    public function getSportTypeObjects(): array
    {
        $sportTypes = [];
        foreach ($this->sportTypes as $sportType) {
            $sportTypeObject = SportType::tryFrom($sportType);
            if ($sportTypeObject instanceof SportType) {
                $sportTypes[] = $sportTypeObject;
            }
        }

        return $sportTypes;
    }
}
