<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\BestEffort\ActivityBestEffort;
use App\Domain\Activity\BestEffort\ActivityBestEffortCalculator;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Segment\SegmentEffort\Matching\SegmentEffortMatcher;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortId;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Recalculates the data derived from an activity: the best efforts and the segment efforts.
 *
 * Everything is calculated without writing, so the result can be presented to the athlete
 * before it is applied. The values that are overwritten are backed up, so the recalculation
 * can be reverted without losing data.
 */
final readonly class ActivityDerivedMetricsRecalculator
{
    public function __construct(
        private ActivityBestEffortRepository $activityBestEffortRepository,
        private SegmentEffortRepository $segmentEffortRepository,
        private SegmentRepository $segmentRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private ActivityBestEffortCalculator $bestEffortCalculator,
        private SegmentEffortMatcher $segmentEffortMatcher,
    ) {
    }

    /**
     * Calculate the best efforts for the activity.
     *
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>
     */
    public function calculateBestEfforts(Activity $activity): array
    {
        $bestEfforts = [];
        foreach ($this->bestEffortCalculator->calculate($activity->getId()) as $bestEffort) {
            $bestEfforts[] = [
                'distanceInMeter' => $bestEffort->getDistanceInMeter()->toInt(),
                'timeInSeconds' => $bestEffort->getTimeInSeconds(),
                'sportType' => $bestEffort->getSportType()->value,
            ];
        }

        return $bestEfforts;
    }

    /**
     * Calculate the segment efforts for the activity.
     *
     * The segments that are already associated with the activity are kept, only their
     * times are recalculated. A segment that can no longer be matched keeps its
     * original values.
     *
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>
     */
    public function calculateSegmentEfforts(Activity $activity): array
    {
        $currentEfforts = $this->segmentEffortRepository->findByActivityId($activity->getId());
        if ($currentEfforts->isEmpty()) {
            return [];
        }

        $streams = $this->activityStreamRepository->findByActivityId($activity->getId());
        $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
        $time = $streams->filterOnType(StreamType::TIME)?->getData() ?? [];
        $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
        if (in_array([], [$time, $distance, $latLng], true)) {
            return [];
        }

        $recalculated = [];
        foreach ($currentEfforts as $currentEffort) {
            try {
                $segment = $this->segmentRepository->find($currentEffort->getSegmentId());
            } catch (EntityNotFound) {
                continue;
            }

            $matched = $this->segmentEffortMatcher->match(
                segment: $segment,
                latLng: $latLng,
                time: $time,
                distance: $distance,
                watts: $streams->filterOnType(StreamType::WATTS)?->getData() ?? [],
                heartRate: $streams->filterOnType(StreamType::HEART_RATE)?->getData() ?? [],
            );
            if ([] === $matched) {
                // The segment can no longer be matched, it keeps its original values.
                continue;
            }

            $best = $matched[0];
            $recalculated[] = [
                'segmentEffortId' => $currentEffort->getId()->toUnprefixedString(),
                'segmentId' => $currentEffort->getSegmentId()->toUnprefixedString(),
                'startDateTime' => $currentEffort->getStartDateTime()->format('Y-m-d H:i:s'),
                'elapsedTimeInSeconds' => $best->getElapsedTimeInSeconds(),
                'distance' => $currentEffort->getDistance()->toFloat(),
                // Only the time is recalculated, the other values are kept.
                'averageWatts' => $currentEffort->getAverageWatts(),
                'averageHeartRate' => $currentEffort->getAverageHeartRate(),
                'maxHeartRate' => $currentEffort->getMaxHeartRate(),
            ];
        }

        return $recalculated;
    }

    /**
     * Apply the recalculated best efforts, the ones that are not recalculated keep their value.
     *
     * @param list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}> $recalculated
     */
    public function applyBestEfforts(Activity $activity, array $recalculated): void
    {
        if ([] === $recalculated) {
            return;
        }

        $current = [];
        foreach ($this->activityBestEffortRepository->findAllByActivity($activity->getId()) as $bestEffort) {
            $current[$bestEffort->getDistanceInMeter()->toInt()] = $bestEffort;
        }

        foreach ($recalculated as $bestEffort) {
            $distance = $bestEffort['distanceInMeter'];
            $current[$distance] = ActivityBestEffort::create(
                activityId: $activity->getId(),
                distanceInMeter: Meter::from($distance),
                sportType: SportType::from($bestEffort['sportType']),
                timeInSeconds: $bestEffort['timeInSeconds'],
            );
        }

        $this->activityBestEffortRepository->deleteForActivity($activity->getId());
        foreach ($current as $bestEffort) {
            $this->activityBestEffortRepository->add($bestEffort);
        }
    }

    /**
     * Apply the recalculated segment efforts, the ones that are not recalculated keep their value.
     *
     * @param list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}> $recalculated
     */
    public function applySegmentEfforts(Activity $activity, array $recalculated): void
    {
        if ([] === $recalculated) {
            return;
        }

        $current = [];
        foreach ($this->segmentEffortRepository->findByActivityId($activity->getId()) as $segmentEffort) {
            $current[$segmentEffort->getId()->toUnprefixedString()] = $segmentEffort;
        }

        foreach ($recalculated as $segmentEffort) {
            $id = $segmentEffort['segmentEffortId'];
            if (!isset($current[$id])) {
                continue;
            }
            $current[$id] = SegmentEffort::create(
                segmentEffortId: SegmentEffortId::fromUnprefixed($id),
                segment: $this->segmentRepository->find(SegmentId::fromUnprefixed($segmentEffort['segmentId'])),
                activityId: $activity->getId(),
                startDateTime: SerializableDateTime::fromString($segmentEffort['startDateTime']),
                elapsedTimeInSeconds: $segmentEffort['elapsedTimeInSeconds'],
                distance: Kilometer::from($segmentEffort['distance']),
                averageWatts: $segmentEffort['averageWatts'],
                averageHeartRate: $segmentEffort['averageHeartRate'],
                maxHeartRate: $segmentEffort['maxHeartRate'],
            );
        }

        $this->segmentEffortRepository->deleteForActivity($activity->getId());
        foreach ($current as $segmentEffort) {
            $this->segmentEffortRepository->add($segmentEffort);
        }
    }

    /**
     * @return list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}>
     */
    public function backupBestEfforts(ActivityId $activityId): array
    {
        $backup = [];
        foreach ($this->activityBestEffortRepository->findAllByActivity($activityId) as $bestEffort) {
            $backup[] = [
                'distanceInMeter' => $bestEffort->getDistanceInMeter()->toInt(),
                'timeInSeconds' => $bestEffort->getTimeInSeconds(),
                'sportType' => $bestEffort->getSportType()->value,
            ];
        }

        return $backup;
    }

    /**
     * @return list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}>
     */
    public function backupSegmentEfforts(ActivityId $activityId): array
    {
        $backup = [];
        foreach ($this->segmentEffortRepository->findByActivityId($activityId) as $segmentEffort) {
            $backup[] = [
                'segmentEffortId' => $segmentEffort->getId()->toUnprefixedString(),
                'segmentId' => $segmentEffort->getSegmentId()->toUnprefixedString(),
                'startDateTime' => $segmentEffort->getStartDateTime()->format('Y-m-d H:i:s'),
                'elapsedTimeInSeconds' => $segmentEffort->getElapsedTimeInSeconds(),
                'distance' => $segmentEffort->getDistance()->toFloat(),
                'averageWatts' => $segmentEffort->getAverageWatts(),
                'averageHeartRate' => $segmentEffort->getAverageHeartRate(),
                'maxHeartRate' => $segmentEffort->getMaxHeartRate(),
            ];
        }

        return $backup;
    }

    /**
     * Restore the original best efforts.
     *
     * @param list<array{distanceInMeter: int, timeInSeconds: int, sportType: string}> $originals
     */
    public function restoreBestEfforts(Activity $activity, array $originals): void
    {
        $this->activityBestEffortRepository->deleteForActivity($activity->getId());

        foreach ($originals as $original) {
            $this->activityBestEffortRepository->add(ActivityBestEffort::create(
                activityId: $activity->getId(),
                distanceInMeter: Meter::from($original['distanceInMeter']),
                sportType: SportType::from($original['sportType']),
                timeInSeconds: $original['timeInSeconds'],
            ));
        }
    }

    /**
     * Restore the original segment efforts.
     *
     * @param list<array{segmentEffortId: string, segmentId: string, startDateTime: string, elapsedTimeInSeconds: float, distance: float, averageWatts: float|null, averageHeartRate: int|null, maxHeartRate: int|null}> $originals
     */
    public function restoreSegmentEfforts(Activity $activity, array $originals): void
    {
        $this->segmentEffortRepository->deleteForActivity($activity->getId());

        foreach ($originals as $original) {
            $this->segmentEffortRepository->add(SegmentEffort::create(
                segmentEffortId: SegmentEffortId::fromUnprefixed($original['segmentEffortId']),
                segment: $this->segmentRepository->find(SegmentId::fromUnprefixed($original['segmentId'])),
                activityId: $activity->getId(),
                startDateTime: SerializableDateTime::fromString($original['startDateTime']),
                elapsedTimeInSeconds: $original['elapsedTimeInSeconds'],
                distance: Kilometer::from($original['distance']),
                averageWatts: $original['averageWatts'],
                averageHeartRate: $original['averageHeartRate'],
                maxHeartRate: $original['maxHeartRate'],
            ));
        }
    }
}
