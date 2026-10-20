<?php

declare(strict_types=1);

namespace App\Domain\Activity\RevertActivityMetrics;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Recalculate\ActivityDerivedMetricsRecalculator;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class RevertActivityMetricsCommandHandler implements CommandHandler
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityDerivedMetricsRecalculator $derivedMetricsRecalculator,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof RevertActivityMetrics);

        $activityWithRawData = $this->activityRepository->findWithRawData($command->getActivityId());
        $activity = $activityWithRawData->getActivity();
        $recalculated = $activity->getRecalculatedMetrics();

        // The best efforts and the segment efforts are backed up when they are
        // recalculated, so they can be restored to their original values.
        if ($recalculated instanceof \App\Domain\Activity\RecalculatedMetrics) {
            $originalBestEfforts = $recalculated->getOriginalBestEfforts();
            if (null !== $originalBestEfforts) {
                $this->derivedMetricsRecalculator->restoreBestEfforts($activity, $originalBestEfforts);
            }
            $originalSegmentEfforts = $recalculated->getOriginalSegmentEfforts();
            if (null !== $originalSegmentEfforts) {
                $this->derivedMetricsRecalculator->restoreSegmentEfforts($activity, $originalSegmentEfforts);
            }
        }

        $this->activityRepository->update(ActivityWithRawData::fromState(
            activity: $activity
                ->withoutRecalculatedMetrics()
                ->withoutPendingRecalculation(),
            rawData: $activityWithRawData->getRawData(),
        ));
    }
}
