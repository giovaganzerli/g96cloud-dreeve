<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\ActivityRepository;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;

final readonly class CollectionSummaryBuilder
{
    public function __construct(
        private ActivityRepository $activityRepository,
    ) {
    }

    /**
     * @return CollectionSummary[]
     */
    public function build(Collections $collections): array
    {
        $summaries = [];
        foreach ($collections as $collection) {
            $summaries[] = $this->buildFor($collection);
        }

        return $summaries;
    }

    public function buildFor(Collection $collection): CollectionSummary
    {
        $activities = [];
        if (!$collection->getActivityIds()->isEmpty()) {
            $activities = $this->activityRepository->findByIds($collection->getActivityIds());
        }

        $totalDistance = 0.0;
        $totalElevation = 0.0;
        $movingTimeInSeconds = 0;
        $elapsedTimeInSeconds = 0;
        $startDate = null;
        $endDate = null;
        $countryCodes = [];
        $sportTypes = [];
        $deviceIds = [];

        foreach ($activities as $activity) {
            $totalDistance += $activity->getDistance()->toMeter()->toFloat();
            $totalElevation += $activity->getElevation()->toFloat();
            $movingTimeInSeconds += $activity->getMovingTimeInSeconds();
            $elapsedTimeInSeconds += $activity->getElapsedTimeInSeconds();

            $activityStartDate = $activity->getStartDate();
            if (!$startDate instanceof \App\Infrastructure\ValueObject\Time\SerializableDateTime || $activityStartDate < $startDate) {
                $startDate = $activityStartDate;
            }
            if (!$endDate instanceof \App\Infrastructure\ValueObject\Time\SerializableDateTime || $activityStartDate > $endDate) {
                $endDate = $activityStartDate;
            }

            foreach ($activity->getRouteGeography()->getPassedThroughCountries() as $countryCode) {
                $countryCodes[$countryCode] = true;
            }

            $sportTypes[$activity->getSportType()->value] = true;

            $deviceIds[(string) $activity->getDeviceId()] = true;
        }

        return new CollectionSummary(
            collection: $collection,
            numberOfActivities: count($activities),
            totalDistance: Kilometer::from($totalDistance / 1000),
            totalElevation: Meter::from($totalElevation),
            movingTimeInSeconds: $movingTimeInSeconds,
            elapsedTimeInSeconds: $elapsedTimeInSeconds,
            startDate: $startDate,
            endDate: $endDate,
            countryCodes: array_keys($countryCodes),
            sportTypes: array_keys($sportTypes),
            deviceIds: array_keys($deviceIds),
        );
    }
}
