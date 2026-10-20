<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Time\Seconds;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use App\Infrastructure\Time\Format\ProvideTimeFormats;

/**
 * Metrics recalculated from the map data and the activity file.
 *
 * Every value is optional: only the metrics that were recalculated are stored here.
 * The values reported by the device stay untouched in the activity itself, so
 * the recalculated metrics can always be reverted.
 *
 * The best efforts and the segment efforts live in their own tables. When they are
 * recalculated, the original values are stored here, so reverting the recalculation
 * restores them exactly.
 */
final readonly class RecalculatedMetrics implements \JsonSerializable
{
    use ProvideTimeFormats;

    /**
     * @param list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>|null                                                                                                                                   $originalBestEfforts
     * @param list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>|null $originalSegmentEfforts
     */
    public function __construct(
        private ?Kilometer $distance = null,
        private ?Meter $elevation = null,
        private ?int $movingTimeInSeconds = null,
        private ?int $elapsedTimeInSeconds = null,
        private ?KmPerHour $averageSpeed = null,
        private ?array $originalBestEfforts = null,
        private ?array $originalSegmentEfforts = null,
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
            originalBestEfforts: self::readOriginalBestEfforts($data['originalBestEfforts'] ?? null),
            originalSegmentEfforts: self::readOriginalSegmentEfforts($data['originalSegmentEfforts'] ?? null),
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
            'originalBestEfforts' => $this->originalBestEfforts,
            'originalSegmentEfforts' => $this->originalSegmentEfforts,
        ];
    }

    /**
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>|null
     */
    public function getOriginalBestEfforts(): ?array
    {
        return $this->originalBestEfforts;
    }

    /**
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>|null
     */
    public function getOriginalSegmentEfforts(): ?array
    {
        return $this->originalSegmentEfforts;
    }

    /**
     * @param list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>                                                                                                                                   $originalBestEfforts
     * @param list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}> $originalSegmentEfforts
     */
    public function withOriginals(array $originalBestEfforts, array $originalSegmentEfforts): self
    {
        return new self(
            distance: $this->distance,
            elevation: $this->elevation,
            movingTimeInSeconds: $this->movingTimeInSeconds,
            elapsedTimeInSeconds: $this->elapsedTimeInSeconds,
            averageSpeed: $this->averageSpeed,
            originalBestEfforts: $originalBestEfforts,
            originalSegmentEfforts: $originalSegmentEfforts,
        );
    }

    /**
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>|null
     */
    private static function readOriginalBestEfforts(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $bestEfforts = [];
        foreach ($data as $bestEffort) {
            if (!is_array($bestEffort)
                || !isset($bestEffort['distanceInMeter'], $bestEffort['timeInSeconds'], $bestEffort['sportType'])
                || !is_numeric($bestEffort['distanceInMeter'])
                || !is_numeric($bestEffort['timeInSeconds'])
                || !is_string($bestEffort['sportType'])) {
                return null;
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
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>|null
     */
    private static function readOriginalSegmentEfforts(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
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
                return null;
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

    public function getMovingTimeFormatted(): ?string
    {
        return null === $this->movingTimeInSeconds ? null : $this->formatDurationAsClock($this->movingTimeInSeconds);
    }

    public function getMovingTime(): ?Seconds
    {
        return null === $this->movingTimeInSeconds ? null : Seconds::from($this->movingTimeInSeconds);
    }

    public function getElapsedTimeInSeconds(): ?int
    {
        return $this->elapsedTimeInSeconds;
    }

    public function getElapsedTimeFormatted(): ?string
    {
        return null === $this->elapsedTimeInSeconds ? null : $this->formatDurationAsClock($this->elapsedTimeInSeconds);
    }

    public function getElapsedTime(): ?Seconds
    {
        return null === $this->elapsedTimeInSeconds ? null : Seconds::from($this->elapsedTimeInSeconds);
    }

    public function getAverageSpeed(): ?KmPerHour
    {
        return $this->averageSpeed;
    }

    public function isEmpty(): bool
    {
        return !$this->distance instanceof Kilometer
            && !$this->elevation instanceof Meter
            && null === $this->movingTimeInSeconds
            && null === $this->elapsedTimeInSeconds
            && !$this->averageSpeed instanceof KmPerHour;
    }

    /**
     * Merge the recalculated values into the current ones. Values that are not
     * recalculated keep whatever was recalculated before. The backup of the
     * original best efforts and segment efforts is kept as-is.
     */
    public function merge(self $other): self
    {
        return new self(
            distance: $other->distance ?? $this->distance,
            elevation: $other->elevation ?? $this->elevation,
            movingTimeInSeconds: $other->movingTimeInSeconds ?? $this->movingTimeInSeconds,
            elapsedTimeInSeconds: $other->elapsedTimeInSeconds ?? $this->elapsedTimeInSeconds,
            averageSpeed: $other->averageSpeed ?? $this->averageSpeed,
            originalBestEfforts: $this->originalBestEfforts,
            originalSegmentEfforts: $this->originalSegmentEfforts,
        );
    }
}
