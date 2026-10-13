<?php

declare(strict_types=1);

namespace App\Application\Import\CalculateActivityMetrics\Pipeline;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Route\Signature\RouteGrid;
use App\Domain\Activity\Scan\ActivityScan;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\ImportMode;
use App\Domain\Segment\SegmentEffort\Matching\SegmentEffortMatcher;
use App\Domain\Segment\SegmentEffort\Matching\SegmentGeometryFromEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortId;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentRepository;
use App\Domain\Segment\SegmentType;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 2)]
final readonly class CalculateImportedSegmentEfforts implements CalculateActivityMetricsStep
{
    private const string CACHE_KEY = 'g96.localSegmentGeometry.v1';

    public function __construct(
        private Connection $connection,
        private ImportMode $importMode,
        private SegmentRepository $segmentRepository,
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private ActivityScanRepository $activityScanRepository,
        private SegmentEffortRepository $segmentEffortRepository,
        private RouteGrid $routeGrid,
        private SegmentEffortMatcher $matcher,
        private SegmentGeometryFromEffort $geometryBuilder,
    ) {
    }

    public function process(OutputInterface $output): void
    {
        if (!$this->importMode->isFiles()) {
            return;
        }
        $this->scan($output);
    }

    public function searchActivity(ActivityId $activityId): void
    {
        $this->scan(new NullOutput(), $activityId);
    }

    private function scan(OutputInterface $output, ?ActivityId $selectedActivityId = null): void
    {
        $fingerprint = hash('sha256', Json::encode($this->connection->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM Segment WHERE type = 'imported') AS segments,
             (SELECT COUNT(*) FROM Activity WHERE importSource = 'stravaApi') AS activities,
             (SELECT MAX(st.rowid) FROM ActivityStream st JOIN Activity a ON a.activityId = st.activityId WHERE a.importSource = 'stravaApi' AND st.streamType IN ('latlng', 'distance')) AS streams"
        )));
        $ids = null !== $selectedActivityId ? [(string) $selectedActivityId] : $this->connection->fetchFirstColumn('SELECT a.activityId FROM Activity a
            WHERE a.importSource IN (:sources) AND NOT EXISTS (SELECT 1 FROM ActivityScan s
            WHERE s.activityId = a.activityId AND s.type = :type AND s.subjectId = :revision)
            AND EXISTS (SELECT 1 FROM ActivityStream st WHERE st.activityId = a.activityId AND st.streamType = :gps AND st.dataSize > 0)',
            ['sources' => array_map(fn (ImportSource $source): string => $source->value, ImportSource::fileBasedSources()),
             'type' => ActivityScanType::LOCAL_IMPORTED_SEGMENTS->value, 'revision' => $fingerprint, 'gps' => StreamType::LAT_LNG->value],
            ['sources' => ArrayParameterType::STRING]);
        if ([] === $ids) {
            return;
        }
        $cached = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => self::CACHE_KEY]);
        $cache = false !== $cached ? Json::decode($cached) : [];
        if (($cache['fingerprint'] ?? null) !== $fingerprint) {
            $geometries = [];
            // Reconstruction is cached separately; never overwrite official Segment geometries.
            foreach ($this->connection->iterateAssociative("SELECT activityId, data FROM Activity WHERE importSource = 'stravaApi' ORDER BY activityId") as $row) {
                $efforts = Json::decode($row['data'])['segment_efforts'] ?? [];
                if ([] === $efforts) {
                    continue;
                }
                $streams = $this->activityStreamRepository->findByActivityId(ActivityId::fromString($row['activityId']));
                $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
                $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
                foreach ($efforts as $effort) {
                    $id = 'segment-'.($effort['segment']['id'] ?? '');
                    if (isset($geometries[$id])) {
                        continue;
                    }
                    $polyline = $this->geometryBuilder->reconstruct($effort, $latLng, $distance, (float) ($effort['segment']['distance'] ?? 0));
                    if (null !== $polyline) {
                        $geometries[$id] = ['polyline' => (string) $polyline, 'sourceActivity' => $row['activityId']];
                    }
                }
            }
            $cache = ['fingerprint' => $fingerprint, 'geometries' => $geometries];
            $this->connection->executeStatement('INSERT INTO KeyValue (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
                ['key' => self::CACHE_KEY, 'value' => Json::encode($cache)]);
        }
        $candidates = [];
        $segments = $this->segmentRepository->findByType(SegmentType::IMPORTED)->toArray();
        if (null !== $selectedActivityId) {
            $segments = array_merge($segments, $this->segmentRepository->findByType(SegmentType::CUSTOM)->toArray());
        }
        foreach ($segments as $segment) {
            $polyline = $segment->getPolyline() ?? EncodedPolyline::fromOptionalString($cache['geometries'][(string) $segment->getId()]['polyline'] ?? null);
            if (null === $polyline) {
                continue;
            }
            $segment = $segment->withPolyline($polyline);
            $candidates[] = [$segment, $this->routeGrid->cellsFor($polyline)->toArray()];
        }
        $added = 0;
        foreach ($ids as $id) {
            $activityId = ActivityId::fromString($id);
            $activity = $this->activityRepository->find($activityId);
            if (ImportSource::MANUAL === $activity->getImportSource()) {
                continue;
            }
            $streams = $this->activityStreamRepository->findByActivityId($activityId);
            $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
            $time = $streams->filterOnType(StreamType::TIME)?->getData() ?? [];
            $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
            if ([] === $time || [] === $distance || [] === $latLng) {
                continue;
            }
            $activityCells = array_flip($this->routeGrid->cellsFor(EncodedPolyline::fromCoordinates($latLng))->toArray());
            $this->connection->beginTransaction();
            try {
                // Preserve existing official/custom passes; local passes have deterministic IDs.
                $recordedSegments = array_flip($this->connection->fetchFirstColumn(
                    "SELECT DISTINCT segmentId FROM SegmentEffort WHERE activityId = :id AND segmentEffortId NOT LIKE 'segmentEffort-local-%'",
                    ['id' => $id]));
                foreach ($candidates as [$segment, $cells]) {
                    if (isset($recordedSegments[(string) $segment->getId()])) {
                        continue;
                    }
                    if ($segment->getSportType()->getActivityType() !== $activity->getSportType()->getActivityType()
                        || count(array_filter($cells, static fn (int $cell): bool => isset($activityCells[$cell]))) < count($cells) * 0.5) {
                        continue;
                    }
                    if (null !== $selectedActivityId && $segment->getType()->isCustom()) {
                        $this->activityScanRepository->add(ActivityScan::create($activityId, ActivityScanType::CUSTOM_SEGMENT, (string) $segment->getId()));
                    }
                    foreach ($this->matcher->match($segment, $latLng, $time, $distance,
                        $streams->filterOnType(StreamType::WATTS)?->getData() ?? [],
                        $streams->filterOnType(StreamType::HEART_RATE)?->getData() ?? []) as $matched) {
                        $effortId = SegmentEffortId::fromUnprefixed('local-'.hash('sha256', $id.':'.$segment->getId().':'.$matched->getStartIndex()));
                        if (false !== $this->connection->fetchOne('SELECT 1 FROM SegmentEffort WHERE segmentEffortId = :id', ['id' => $effortId])) {
                            continue;
                        }
                        $this->segmentEffortRepository->add(SegmentEffort::create(
                            $effortId, $segment, $activityId,
                            $activity->getStartDate()->modify(sprintf('+%d seconds', (int) $time[$matched->getStartIndex()])),
                            $matched->getElapsedTimeInSeconds(), $segment->getDistance(),
                            $matched->getAverageWatts(), $matched->getAverageHeartRate(), $matched->getMaxHeartRate()));
                        ++$added;
                    }
                }
                if (null === $selectedActivityId) {
                    $this->activityScanRepository->add(ActivityScan::create($activityId, ActivityScanType::LOCAL_IMPORTED_SEGMENTS, $fingerprint));
                }
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }
        $output->writeln(sprintf('  => Local imported segments: %d geometries available, %d file activities scanned, %d efforts added', count($candidates), count($ids), $added));
    }
}
