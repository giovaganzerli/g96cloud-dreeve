<?php

declare(strict_types=1);

namespace App\Domain\Activity\ApplyActivityRecalculation;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Recalculate\ActivityDerivedMetricsRecalculator;
use App\Domain\Activity\RecalculatedMetrics;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class ApplyActivityRecalculationCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityDerivedMetricsRecalculator $derivedMetricsRecalculator,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ApplyActivityRecalculation);

        $activityWithRawData = $this->activityRepository->findWithRawData($command->getActivityId());
        $activity = $activityWithRawData->getActivity();
        $pending = $activity->getPendingRecalculation();
        if (!$pending instanceof \App\Domain\Activity\Recalculate\ActivityRecalculation) {
            return;
        }

        $selectedMetrics = $command->getMetrics();

        // The values reported by the device are backed up before they are overwritten,
        // so the recalculation can be reverted. The backup is only taken once.
        $existing = $activity->getRecalculatedMetrics();
        $originalBestEfforts = $existing instanceof RecalculatedMetrics && null !== $existing->getOriginalBestEfforts()
            ? $existing->getOriginalBestEfforts()
            : $this->derivedMetricsRecalculator->backupBestEfforts($activity->getId());
        $originalSegmentEfforts = $existing instanceof RecalculatedMetrics && null !== $existing->getOriginalSegmentEfforts()
            ? $existing->getOriginalSegmentEfforts()
            : $this->derivedMetricsRecalculator->backupSegmentEfforts($activity->getId());

        $metrics = new RecalculatedMetrics(
            distance: in_array('distance', $selectedMetrics, true) ? $pending->getDistance() : null,
            elevation: in_array('elevation', $selectedMetrics, true) ? $pending->getElevation() : null,
            movingTimeInSeconds: in_array('movingTime', $selectedMetrics, true) ? $pending->getMovingTimeInSeconds() : null,
            elapsedTimeInSeconds: in_array('elapsedTime', $selectedMetrics, true) ? $pending->getElapsedTimeInSeconds() : null,
            averageSpeed: in_array('averageSpeed', $selectedMetrics, true) ? $pending->getAverageSpeed() : null,
        );

        if ($command->shouldRecalculateBestEfforts()) {
            $this->derivedMetricsRecalculator->applyBestEfforts($activity, $pending->getBestEfforts());
        }

        if ($command->shouldRecalculateSegmentEfforts()) {
            $this->derivedMetricsRecalculator->applySegmentEfforts($activity, $pending->getSegmentEfforts());
        }

        $this->activityRepository->update(ActivityWithRawData::fromState(
            activity: $activity
                ->withRecalculatedMetrics($metrics->withOriginals($originalBestEfforts, $originalSegmentEfforts))
                ->withoutPendingRecalculation(),
            rawData: $activityWithRawData->getRawData(),
        ));
    }
}
