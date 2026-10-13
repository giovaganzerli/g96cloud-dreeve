<?php

declare(strict_types=1);

namespace App\Domain\Segment\Geometry;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Segment\Segment;
use App\Domain\Segment\SegmentEffort\Matching\SegmentGeometryFromEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Doctrine\DBAL\Connection;

final readonly class GenerateSegmentGeometry
{
    public function __construct(
        private Connection $connection,
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $streamRepository,
        private SegmentEffortRepository $effortRepository,
        private SegmentGeometryFromEffort $builder,
    ) {
    }

    public function generate(Segment $segment): ?EncodedPolyline
    {
        $visited = [];
        foreach ($this->effortRepository->findBySegmentId($segment->getId()) as $effort) {
            $id = $effort->getActivityId();
            $streams = $this->streamRepository->findByActivityId($id);
            $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
            $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
            if ([] === $latLng || [] === $distance) {
                continue;
            }
            $length = $segment->getDistance()->toMeter()->toFloat();
            if (!isset($visited[(string) $id])) {
                $raw = $this->connection->fetchOne('SELECT data FROM Activity WHERE activityId = :id', ['id' => $id]);
                foreach (false !== $raw ? (Json::decode($raw)['segment_efforts'] ?? []) : [] as $archivedEffort) {
                    if ('segment-'.($archivedEffort['segment']['id'] ?? '') !== (string) $segment->getId()) {
                        continue;
                    }
                    if (null !== ($polyline = $this->builder->reconstruct($archivedEffort, $latLng, $distance, $length))) {
                        return $polyline;
                    }
                }
                $visited[(string) $id] = true;
            }
            // Also support locally archived passes when Strava's original stream indexes are absent.
            $time = $streams->filterOnType(StreamType::TIME)?->getData() ?? [];
            $startOffset = $effort->getStartDateTime()->getTimestamp() - $this->activityRepository->find($id)->getStartDate()->getTimestamp();
            $start = $this->nearestIndex($time, $startOffset);
            $end = $this->nearestIndex($time, $startOffset + $effort->getElapsedTimeInSeconds());
            if (null !== $start && null !== $end && null !== ($polyline = $this->builder->reconstruct(['start_index' => $start, 'end_index' => $end], $latLng, $distance, $length))) {
                return $polyline;
            }
        }
        return null;
    }

    private function nearestIndex(array $time, float $offset): ?int
    {
        $nearest = null;
        $delta = 5.0;
        foreach ($time as $index => $value) {
            if (is_numeric($value) && abs($value - $offset) <= $delta) {
                $delta = abs($value - $offset);
                $nearest = (int) $index;
            }
        }
        return $nearest;
    }
}
