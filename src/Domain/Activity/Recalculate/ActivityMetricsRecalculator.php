<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Domain\Activity\Activity;
use App\Domain\Activity\RecalculatedMetrics;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileParser\StreamMath;
use App\Domain\Integration\Elevation\OpenMeteo\OpenMeteoElevation;
use App\Domain\Integration\Elevation\TerrainTiles\TerrainTilesElevation;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use App\Infrastructure\Serialization\Json;
use Doctrine\DBAL\Connection;

/**
 * Recalculates the activity metrics from the map data and the data in the activity file.
 *
 * The values reported by the device are never modified, the recalculated values are
 * stored separately so they can always be reverted.
 */
final readonly class ActivityMetricsRecalculator
{
    /**
     * The maximum number of coordinates used for the elevation lookup. The elevation
     * gain is calculated on a sampled track, a higher resolution does not add
     * meaningful accuracy because the smoothing window is expressed in meters.
     */
    private const int MAX_ELEVATION_POINTS = 300;

    /**
     * The elevation service has a low request limit, so the results are cached.
     * The cache is keyed by the track, a changed track results in a new lookup.
     */
    private const string ELEVATION_CACHE_KEY = 'g96.activityElevations.v2';

    private const int ELEVATION_CACHE_ENTRIES = 20;

    public function __construct(
        private ActivityStreamRepository $activityStreamRepository,
        private OpenMeteoElevation $openMeteoElevation,
        private TerrainTilesElevation $terrainTilesElevation,
        private ElevationGainCalculator $elevationGainCalculator,
        private Connection $connection,
    ) {
    }

    /**
     * @param list<RecalculationType> $types
     */
    public function recalculate(Activity $activity, array $types): RecalculatedMetrics
    {
        $streams = $this->activityStreamRepository->findByActivityId($activity->getId());
        $coordinates = $this->extractCoordinates($streams);
        $times = $this->extractTimes($streams);

        $distance = null;
        $elevation = null;
        $movingTime = null;
        $elapsedTime = null;

        if (in_array(RecalculationType::DISTANCE, $types, true)) {
            $distance = $this->calculateMapDistance($coordinates);
        }

        if (in_array(RecalculationType::ELEVATION, $types, true)) {
            $elevation = $this->calculateMapElevation($coordinates);
        }

        if (in_array(RecalculationType::TIME, $types, true)) {
            [$movingTime, $elapsedTime] = $this->calculateTimes(
                sportType: $activity->getSportType(),
                times: $times,
                coordinates: $coordinates,
                deviceDistances: $this->extractDistances($streams),
            );
        }

        // The average speed follows from the effective distance and moving time,
        // so it has to be recalculated whenever one of them changes.
        $averageSpeed = null;
        if ($distance instanceof Kilometer || null !== $movingTime) {
            $averageSpeed = $this->calculateAverageSpeed(
                distance: $distance ?? $activity->getDistance(),
                movingTimeInSeconds: $movingTime ?? $activity->getMovingTimeInSeconds(),
            );
        }

        return new RecalculatedMetrics(
            distance: $distance,
            elevation: $elevation,
            movingTimeInSeconds: $movingTime,
            elapsedTimeInSeconds: $elapsedTime,
            averageSpeed: $averageSpeed,
        );
    }

    /**
     * @return list<array{float, float}>
     */
    private function extractCoordinates(ActivityStreams $streams): array
    {
        $coordinates = [];
        foreach ($streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? [] as $point) {
            if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $coordinates[] = [(float) $point[0], (float) $point[1]];
        }

        return $coordinates;
    }

    /**
     * @return list<int>
     */
    private function extractTimes(ActivityStreams $streams): array
    {
        $times = [];
        foreach ($streams->filterOnType(StreamType::TIME)?->getData() ?? [] as $time) {
            if (is_numeric($time)) {
                $times[] = (int) $time;
            }
        }

        return $times;
    }

    /**
     * @return list<float>
     */
    private function extractDistances(ActivityStreams $streams): array
    {
        $distances = [];
        foreach ($streams->filterOnType(StreamType::DISTANCE)?->getData() ?? [] as $distance) {
            if (is_numeric($distance)) {
                $distances[] = (float) $distance;
            }
        }

        return $distances;
    }

    /**
     * @param list<array{float, float}> $coordinates
     */
    private function calculateMapDistance(array $coordinates): Kilometer
    {
        if ([] === $coordinates) {
            throw CouldNotRecalculateActivityMetrics::becauseThereIsNoCoordinateData();
        }

        $distances = StreamMath::deriveDistanceStream($coordinates);

        return Kilometer::from(round(end($distances) / 1000, 3));
    }

    /**
     * @param list<array{float, float}> $coordinates
     */
    private function calculateMapElevation(array $coordinates): Meter
    {
        if ([] === $coordinates) {
            throw CouldNotRecalculateActivityMetrics::becauseThereIsNoCoordinateData();
        }

        $sampled = $this->sample($coordinates, self::MAX_ELEVATION_POINTS);
        $cumulativeDistances = $this->cumulativeDistances($sampled);

        // The elevation is calculated on multiple sources and averaged, a single
        // digital elevation model is too noisy on its own to be reliable.
        $gains = [];
        foreach ($this->elevationsPerSource($sampled) as $elevations) {
            if (count($elevations) !== count($cumulativeDistances)) {
                continue;
            }
            $gains[] = $this->elevationGainCalculator->calculate($elevations, $cumulativeDistances);
        }

        if ([] === $gains) {
            throw CouldNotRecalculateActivityMetrics::becauseTheElevationIsUnavailable();
        }

        return Meter::from(round(array_sum($gains) / count($gains)));
    }

    /**
     * The cumulative distance (in meters) for every coordinate of the track.
     *
     * @param list<array{float, float}> $coordinates
     *
     * @return list<float>
     */
    private function cumulativeDistances(array $coordinates): array
    {
        $distances = [];
        foreach (StreamMath::deriveDistanceStream($coordinates) as $distance) {
            if (null === $distance) {
                continue;
            }
            $distances[] = $distance;
        }

        return $distances;
    }

    /**
     * The elevation for every sampled coordinate, per source. A source that did not
     * return an elevation for every point is incomplete and left out.
     *
     * @param list<array{float, float}> $sampled
     *
     * @return list<list<float>>
     */
    private function elevationsPerSource(array $sampled): array
    {
        $hash = hash('sha256', Json::encode($sampled));

        $cache = $this->elevationCache();
        if (isset($cache[$hash]) && is_array($cache[$hash])) {
            $cached = $cache[$hash];
            $elevations = [];
            foreach (['openMeteo', 'terrainTiles'] as $source) {
                $complete = $this->completeElevations(is_array($cached[$source] ?? null) ? $cached[$source] : []);
                if ([] !== $complete) {
                    $elevations[] = $complete;
                }
            }

            return $elevations;
        }

        $elevations = [
            'openMeteo' => $this->openMeteoElevation->getElevations($sampled),
            'terrainTiles' => $this->terrainTilesElevation->getElevations($sampled),
        ];

        $cache[$hash] = $elevations;
        if (self::ELEVATION_CACHE_ENTRIES < count($cache)) {
            $cache = array_slice($cache, -self::ELEVATION_CACHE_ENTRIES, null, true);
        }
        $this->connection->executeStatement(
            'INSERT INTO KeyValue (`key`, `value`) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['key' => self::ELEVATION_CACHE_KEY, 'value' => Json::encode($cache)]
        );

        $complete = [];
        foreach (array_values($elevations) as $sourceElevations) {
            $values = $this->completeElevations($sourceElevations);
            if ([] !== $values) {
                $complete[] = $values;
            }
        }

        return $complete;
    }

    /**
     * @param array<array-key, mixed> $elevations
     *
     * @return list<float>
     */
    private function completeElevations(array $elevations): array
    {
        if ([] === $elevations) {
            return [];
        }

        $values = [];
        foreach ($elevations as $elevation) {
            if (!is_numeric($elevation)) {
                // The source did not return an elevation for every point.
                return [];
            }
            $values[] = (float) $elevation;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function elevationCache(): array
    {
        $value = $this->connection->fetchOne(
            'SELECT `value` FROM KeyValue WHERE `key` = :key',
            ['key' => self::ELEVATION_CACHE_KEY]
        );

        if (false === $value) {
            return [];
        }

        $cache = Json::decode($value);

        return is_array($cache) ? $cache : [];
    }

    /**
     * @param list<int>                 $times
     * @param list<array{float, float}> $coordinates
     * @param list<float>               $deviceDistances
     *
     * @return array{int, int} the moving time and the elapsed time, both in seconds
     */
    private function calculateTimes(
        SportType $sportType,
        array $times,
        array $coordinates,
        array $deviceDistances,
    ): array {
        if (count($times) < 2) {
            throw CouldNotRecalculateActivityMetrics::becauseThereIsNoTimeData();
        }

        // The distance reported by the device is the most accurate source to detect
        // pauses with, it also works for indoor activities without GPS data.
        $distances = [] !== $deviceDistances
            ? $deviceDistances
            : StreamMath::deriveDistanceStream($coordinates);
        if ([] === $distances) {
            throw CouldNotRecalculateActivityMetrics::becauseThereIsNoDistanceData();
        }

        $parameters = PauseDetectionParameters::for($sportType);
        $movingTime = 0;
        $pauseDuration = 0;
        $count = min(count($times), count($distances));
        for ($index = 1; $index < $count; ++$index) {
            $deltaTime = $times[$index] - $times[$index - 1];
            if ($deltaTime <= 0 || $deltaTime > StreamMath::MAX_RECORDING_GAP_IN_SECONDS) {
                // A recording gap is not a pause, the time is simply unknown.
                continue;
            }
            $deltaDistance = $distances[$index] - $distances[$index - 1];
            if ($deltaDistance / $deltaTime < $parameters->getThresholdInMetersPerSecond()) {
                $pauseDuration += $deltaTime;
                continue;
            }
            if ($pauseDuration > 0) {
                // A short slowdown, like rolling up to a traffic light, is not a pause.
                if ($pauseDuration < $parameters->getMinimumPauseDurationInSeconds()) {
                    $movingTime += $pauseDuration;
                }
                $pauseDuration = 0;
            }
            $movingTime += $deltaTime;
        }

        return [$movingTime, $times[count($times) - 1] - $times[0]];
    }

    private function calculateAverageSpeed(Kilometer $distance, int $movingTimeInSeconds): KmPerHour
    {
        if ($movingTimeInSeconds <= 0) {
            return KmPerHour::zero();
        }

        return KmPerHour::from(round($distance->toFloat() / ($movingTimeInSeconds / 3600), 2));
    }

    /**
     * @param list<array{float, float}> $coordinates
     *
     * @return list<array{float, float}>
     */
    private function sample(array $coordinates, int $maxPoints): array
    {
        $count = count($coordinates);
        if ($count <= $maxPoints) {
            return $coordinates;
        }

        $step = $count / $maxPoints;
        $sampled = [];
        for ($index = 0; $index < $maxPoints; ++$index) {
            $sampled[] = $coordinates[(int) floor($index * $step)];
        }

        return $sampled;
    }
}
