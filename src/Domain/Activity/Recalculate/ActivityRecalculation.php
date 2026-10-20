<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\KmPerHour;

/**
 * The result of a recalculation, waiting to be confirmed by the athlete.
 *
 * It holds the recalculated values for the activity itself and for the data derived
 * from it: the best efforts and the segment efforts.
 */
final readonly class ActivityRecalculation implements \JsonSerializable
{
    /**
     * @param list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>                                                                                                                                   $bestEfforts
     * @param list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}> $segmentEfforts
     */
    public function __construct(
        private ?Kilometer $distance = null,
        private ?Meter $elevation = null,
        private ?int $movingTimeInSeconds = null,
        private ?int $elapsedTimeInSeconds = null,
        private ?KmPerHour $averageSpeed = null,
        private array $bestEfforts = [],
        private array $segmentEfforts = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            distance: isset($data['distance']) && is_numeric($data['distance']) ? Kilometer::from((float) $data['distance']) : null,
            elevation: isset($data['elevation']) && is_numeric($data['elevation']) ? Meter::from((float) $data['elevation']) : null,
            movingTimeInSeconds: isset($data['movingTimeInSeconds']) && is_numeric($data['movingTimeInSeconds']) ? (int) $data['movingTimeInSeconds'] : null,
            elapsedTimeInSeconds: isset($data['elapsedTimeInSeconds']) && is_numeric($data['elapsedTimeInSeconds']) ? (int) $data['elapsedTimeInSeconds'] : null,
            averageSpeed: isset($data['averageSpeed']) && is_numeric($data['averageSpeed']) ? KmPerHour::from((float) $data['averageSpeed']) : null,
            bestEfforts: self::readBestEfforts($data['bestEfforts'] ?? null),
            segmentEfforts: self::readSegmentEfforts($data['segmentEfforts'] ?? null),
        );
    }

    /**
     * @return array<string, float|int|array<mixed>|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'distance' => $this->distance?->toFloat(),
            'elevation' => $this->elevation?->toFloat(),
            'movingTimeInSeconds' => $this->movingTimeInSeconds,
            'elapsedTimeInSeconds' => $this->elapsedTimeInSeconds,
            'averageSpeed' => $this->averageSpeed?->toFloat(),
            'bestEfforts' => $this->bestEfforts,
            'segmentEfforts' => $this->segmentEfforts,
        ];
    }

    public function getDistance(): ?Kilometer
    {
        return $this->distance;
    }

    public function getElevation(): ?Meter
    {
        return $this->elevation;
    }

    public function getMovingTimeInSeconds(): ?int
    {
        return $this->movingTimeInSeconds;
    }

    public function getElapsedTimeInSeconds(): ?int
    {
        return $this->elapsedTimeInSeconds;
    }

    public function getAverageSpeed(): ?KmPerHour
    {
        return $this->averageSpeed;
    }

    /**
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>
     */
    public function getBestEfforts(): array
    {
        return $this->bestEfforts;
    }

    /**
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>
     */
    public function getSegmentEfforts(): array
    {
        return $this->segmentEfforts;
    }

    /**
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>
     */
    private static function readBestEfforts(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $bestEfforts = [];
        foreach ($data as $bestEffort) {
            if (!is_array($bestEffort)
                || !isset($bestEffort['distanceInMeter'], $bestEffort['timeInSeconds'], $bestEffort['sportType'])
                || !is_numeric($bestEffort['distanceInMeter'])
                || !is_numeric($bestEffort['timeInSeconds'])
                || !is_string($bestEffort['sportType'])) {
                return [];
            }
            $bestEfforts[] = [
                'distanceInMeter' => (int) $bestEffort['distanceInMeter'],
                'timeInSeconds' => (int) $bestEffort['timeInSeconds'],
                'sportType' => $bestEffort['sportType'],
            ];
        }

        return $bestEfforts;
    }

    /**
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>
     */
    private static function readSegmentEfforts(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $segmentEfforts = [];
        foreach ($data as $segmentEffort) {
            if (!is_array($segmentEffort)
                || !isset($segmentEffort['segmentEffortId'], $segmentEffort['segmentId'], $segmentEffort['startDateTime'], $segmentEffort['elapsedTimeInSeconds'], $segmentEffort['distance'])
                || !is_string($segmentEffort['segmentEffortId'])
                || !is_string($segmentEffort['segmentId'])
                || !is_string($segmentEffort['startDateTime'])
                || !is_numeric($segmentEffort['elapsedTimeInSeconds'])
                || !is_numeric($segmentEffort['distance'])) {
                return [];
            }
            $segmentEfforts[] = [
                'segmentEffortId' => $segmentEffort['segmentEffortId'],
                'segmentId' => $segmentEffort['segmentId'],
                'startDateTime' => $segmentEffort['startDateTime'],
                'elapsedTimeInSeconds' => (float) $segmentEffort['elapsedTimeInSeconds'],
                'distance' => (float) $segmentEffort['distance'],
                'averageWatts' => isset($segmentEffort['averageWatts']) && is_numeric($segmentEffort['averageWatts']) ? (float) $segmentEffort['averageWatts'] : null,
                'averageHeartRate' => isset($segmentEffort['averageHeartRate']) && is_numeric($segmentEffort['averageHeartRate']) ? (int) $segmentEffort['averageHeartRate'] : null,
                'maxHeartRate' => isset($segmentEffort['maxHeartRate']) && is_numeric($segmentEffort['maxHeartRate']) ? (int) $segmentEffort['maxHeartRate'] : null,
            ];
        }

        return $segmentEfforts;
    }
}
