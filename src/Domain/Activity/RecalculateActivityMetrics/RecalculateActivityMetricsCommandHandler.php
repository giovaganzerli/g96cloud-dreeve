<?php

declare(strict_types=1);

namespace App\Domain\Activity\RecalculateActivityMetrics;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Recalculate\ActivityDerivedMetricsRecalculator;
use App\Domain\Activity\Recalculate\ActivityMetricsRecalculator;
use App\Domain\Activity\Recalculate\ActivityRecalculation;
use App\Domain\Activity\Recalculate\RecalculationType;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class RecalculateActivityMetricsCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityMetricsRecalculator $recalculator,
        private ActivityDerivedMetricsRecalculator $derivedMetricsRecalculator,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof RecalculateActivityMetrics);

        $activityWithRawData = $this->activityRepository->findWithRawData($command->getActivityId());
        $activity = $activityWithRawData->getActivity();

        $metrics = $this->recalculator->recalculate($activity, RecalculationType::cases());

        $this->activityRepository->update(ActivityWithRawData::fromState(
            activity: $activity->withPendingRecalculation(new ActivityRecalculation(
                distance: $metrics->getDistance(),
                elevation: $metrics->getElevation(),
                movingTimeInSeconds: $metrics->getMovingTimeInSeconds(),
                elapsedTimeInSeconds: $metrics->getElapsedTimeInSeconds(),
                averageSpeed: $metrics->getAverageSpeed(),
                bestEfforts: $this->derivedMetricsRecalculator->calculateBestEfforts($activity),
                segmentEfforts: $this->derivedMetricsRecalculator->calculateSegmentEfforts($activity),
            )),
            rawData: $activityWithRawData->getRawData(),
        ));
    }
}
