<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Domain\Activity\Activity;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Segment\SegmentEffort\Matching\SegmentEffortMatcher;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortId;
use App\Domain\Segment\SegmentRepository;
use App\Domain\Segment\SegmentType;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

/**
 * Matches the known segments against the track of an activity.
 *
 * The same matching the import pipeline uses, but for a single activity. The
 * efforts get a deterministic id, so recalculating them twice does not create
 * duplicates.
 */
final readonly class ActivitySegmentEffortMatcher
{
    public function __construct(
        private SegmentRepository $segmentRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private SegmentEffortMatcher $matcher,
    ) {
    }

    /**
     * Match the known segments against the track of an activity.
     *
     * The efforts are not stored, so this can be used to preview the result.
     *
     * @return list<SegmentEffort> the matched efforts
     */
    public function matchForActivity(Activity $activity): array
    {
        // The segments that match an activity depend on the sport of the activity
        // itself, not on the sport used for the pause detection.
        $sportType = $activity->getSportType();
        $streams = $this->activityStreamRepository->findByActivityId($activity->getId());
        $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
        $time = $streams->filterOnType(StreamType::TIME)?->getData() ?? [];
        $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
        $watts = $streams->filterOnType(StreamType::WATTS)?->getData() ?? [];
        $heartRate = $streams->filterOnType(StreamType::HEART_RATE)?->getData() ?? [];

        if (in_array([], [$time, $distance, $latLng], true)) {
            return [];
        }

        $created = [];
        foreach ($this->segmentRepository->findByType(SegmentType::IMPORTED)->toArray() as $segment) {
            $polyline = $segment->getPolyline();
            if (!$polyline instanceof EncodedPolyline) {
                continue;
            }
            if ($segment->getSportType()->getActivityType() !== $sportType->getActivityType()) {
                continue;
            }

            foreach ($this->matcher->match($segment, $latLng, $time, $distance, $watts, $heartRate) as $matched) {
                $effortId = SegmentEffortId::fromUnprefixed('recalculated-'.hash('sha256', $activity->getId().':'.$segment->getId().':'.$matched->getStartIndex()));
                $effort = SegmentEffort::create(
                    segmentEffortId: $effortId,
                    segment: $segment,
                    activityId: $activity->getId(),
                    startDateTime: $activity->getStartDate()->modify(sprintf('+%d seconds', (int) $time[$matched->getStartIndex()])),
                    elapsedTimeInSeconds: $matched->getElapsedTimeInSeconds(),
                    distance: $segment->getDistance(),
                    averageWatts: $matched->getAverageWatts(),
                    averageHeartRate: $matched->getAverageHeartRate(),
                    maxHeartRate: $matched->getMaxHeartRate(),
                );
                $created[] = $effort;
            }
        }

        return $created;
    }
}
