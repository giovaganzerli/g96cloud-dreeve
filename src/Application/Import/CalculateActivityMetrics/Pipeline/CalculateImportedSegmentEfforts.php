<?php

declare(strict_types=1);

namespace App\Application\Import\CalculateActivityMetrics\Pipeline;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Route\Signature\ActivityRouteSignatureRepository;
use App\Domain\Activity\Scan\ActivityScan;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Geography\Grid\RouteGrid;
use App\Domain\Import\ImportMode;
use App\Domain\Segment\LocalSegmentGeometry;
use App\Domain\Segment\SegmentEffort\Matching\SegmentEffortMatcher;
use App\Domain\Segment\SegmentEffort\Matching\SegmentGeometryFromEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortId;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
use App\Domain\Segment\SegmentType;
use App\Infrastructure\Exception\EntityNotFound;
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
        private LocalSegmentGeometry $localSegmentGeometry,
        private ActivityRouteSignatureRepository $activityRouteSignatureRepository,
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

    public function searchSegment(SegmentId $segmentId): void
    {
        $this->scan(new NullOutput(), selectedSegmentId: $segmentId);
    }

    private function scan(OutputInterface $output, ?ActivityId $selectedActivityId = null, ?SegmentId $selectedSegmentId = null): void
    {
        $fingerprint = hash('sha256', Json::encode($this->connection->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM Segment WHERE type = 'imported') AS segments,
             (SELECT COUNT(*) FROM Activity WHERE importSource = 'stravaApi') AS activities,
             (SELECT MAX(st.rowid) FROM ActivityStream st JOIN Activity a ON a.activityId = st.activityId WHERE a.importSource = 'stravaApi' AND st.streamType IN ('latlng', 'distance')) AS streams"
        )));
        $overrides = $this->localSegmentGeometry->overrides();
        $revision = hash('sha256', $fingerprint.Json::encode($overrides));
        $ids = $selectedActivityId instanceof ActivityId ? [(string) $selectedActivityId] : $this->connection->fetchFirstColumn('SELECT a.activityId FROM Activity a
            WHERE a.importSource IN (:sources) AND NOT EXISTS (SELECT 1 FROM ActivityScan s
            WHERE s.activityId = a.activityId AND s.type = :type AND s.subjectId = :revision)
            AND EXISTS (SELECT 1 FROM ActivityStream st WHERE st.activityId = a.activityId AND st.streamType = :gps AND st.dataSize > 0)',
            ['sources' => array_map(fn (ImportSource $source): string => $source->value, ImportSource::fileBasedSources()),
                'type' => ActivityScanType::LOCAL_IMPORTED_SEGMENTS->value, 'revision' => $revision, 'gps' => StreamType::LAT_LNG->value],
            ['sources' => ArrayParameterType::STRING]);
        if ($selectedSegmentId instanceof SegmentId) {
            $segment = $this->segmentRepository->find($selectedSegmentId);
            $ids = $this->connection->fetchFirstColumn("SELECT a.activityId FROM Activity a
                WHERE a.importSource != 'manual' AND a.sportType IN (:sports)
                AND NOT EXISTS (SELECT 1 FROM SegmentEffort e WHERE e.activityId = a.activityId AND e.segmentId = :segment AND e.segmentEffortId NOT LIKE 'segmentEffort-local-%')
                AND EXISTS (SELECT 1 FROM ActivityStream st WHERE st.activityId = a.activityId AND st.streamType = 'latlng' AND st.dataSize > 0)",
                ['sports' => array_map(static fn (SportType $sport): string => $sport->value, $segment->getSportType()->getActivityType()->getSportTypes()->toArray()), 'segment' => $selectedSegmentId],
                ['sports' => ArrayParameterType::STRING]);
        }
        if ([] === $ids) {
            return;
        }
        $cached = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => self::CACHE_KEY]);
        $cache = false !== $cached ? Json::decode($cached) : [];
        if (!$selectedSegmentId instanceof SegmentId && ($cache['fingerprint'] ?? null) !== $fingerprint) {
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
                    if ($polyline instanceof EncodedPolyline) {
                        $geometries[$id] = ['polyline' => (string) $polyline, 'sourceActivity' => $row['activityId']];
                    }
                }
            }
            $cache = ['fingerprint' => $fingerprint, 'geometries' => $geometries];
            $this->connection->executeStatement('INSERT INTO KeyValue (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
                ['key' => self::CACHE_KEY, 'value' => Json::encode($cache)]);
        }
        $candidates = [];
        if ($selectedSegmentId instanceof SegmentId) {
            $segments = [$this->localSegmentGeometry->forDisplay($this->segmentRepository->find($selectedSegmentId))];
        } else {
            $segments = $this->segmentRepository->findByType(SegmentType::IMPORTED)->toArray();
            if ($selectedActivityId instanceof ActivityId) {
                $segments = array_merge($segments, $this->segmentRepository->findByType(SegmentType::CUSTOM)->toArray());
            }
        }
        foreach ($segments as $segment) {
            $polyline = $segment->getPolyline() ?? EncodedPolyline::fromOptionalString($overrides[(string) $segment->getId()]['polyline'] ?? null) ?? EncodedPolyline::fromOptionalString($cache['geometries'][(string) $segment->getId()]['polyline'] ?? null);
            if (null === $polyline) {
                continue;
            }
            $segment = $segment->withPolyline($polyline);
            $candidates[] = [$segment, RouteGrid::cellsFor($polyline)->toArray()];
        }
        $added = 0;
        foreach ($ids as $id) {
            $activityId = ActivityId::fromString($id);
            $activity = $this->activityRepository->find($activityId);
            if (ImportSource::MANUAL === $activity->getImportSource()) {
                continue;
            }
            if ($selectedSegmentId instanceof SegmentId && [] !== $candidates) {
                try {
                    $routeCells = array_flip($this->activityRouteSignatureRepository->find($activityId)->getCells()->toArray());
                    $cells = $candidates[0][1];
                    if (count(array_filter($cells, static fn (int $cell): bool => isset($routeCells[$cell]))) < count($cells) * 0.5) {
                        continue;
                    }
                } catch (EntityNotFound) {
                    // Older imports may not have a route signature; use the GPS stream below.
                }
            }
            $streams = $this->activityStreamRepository->findByActivityId($activityId);
            $latLng = $streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [];
            $time = $streams->filterOnType(StreamType::TIME)?->getData() ?? [];
            $distance = $streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [];
            if (in_array([], [$time, $distance, $latLng], true)) {
                continue;
            }
            $activityCells = array_flip(RouteGrid::cellsFor(EncodedPolyline::fromCoordinates($latLng))->toArray());
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
                    if (($selectedActivityId instanceof ActivityId || $selectedSegmentId instanceof SegmentId) && $segment->getType()->isCustom()) {
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
                if (!$selectedActivityId instanceof ActivityId && !$selectedSegmentId instanceof SegmentId) {
                    $this->activityScanRepository->add(ActivityScan::create($activityId, ActivityScanType::LOCAL_IMPORTED_SEGMENTS, $revision));
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
