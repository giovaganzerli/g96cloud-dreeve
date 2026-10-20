<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Recalculate;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Recalculate\ActivityMetricsRecalculator;
use App\Domain\Activity\Recalculate\CouldNotRecalculateActivityMetrics;
use App\Domain\Activity\Recalculate\ElevationGainCalculator;
use App\Domain\Activity\Recalculate\RecalculationType;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Integration\Elevation\OpenMeteo\OpenMeteoElevation;
use App\Domain\Integration\Elevation\TerrainTiles\TerrainTilesElevation;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Integration\Elevation\OpenMeteo\SpyOpenMeteoElevation;
use App\Tests\Domain\Integration\Elevation\TerrainTiles\SpyTerrainTilesElevation;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class ActivityMetricsRecalculatorTest extends TestCase
{
    /**
     * @var list<ActivityStream>
     */
    private array $streams = [];

    public function testItCalculatesTheDistanceFromTheCoordinates(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::LAT_LNG, [
            [50.0, 4.0],
            [50.01, 4.0],
        ]);
        $this->provideStream(StreamType::TIME, [1000, 1010]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::DISTANCE]);

        // 0.01 degrees latitude is roughly 1113 meters.
        $this->assertEqualsWithDelta(1.113, $metrics->getDistance()?->toFloat() ?? 0, 0.05);
        $this->assertNull($metrics->getElevation());
        $this->assertNull($metrics->getMovingTimeInSeconds());
    }

    public function testItCalculatesTheElevationFromTheElevationService(): void
    {
        $activity = $this->provideActivity();
        // The points are far enough apart for the smoothing window to cover a single point.
        $this->provideStream(StreamType::LAT_LNG, [
            [50.0, 4.0],
            [50.1, 4.0],
            [50.2, 4.0],
        ]);
        $this->provideStream(StreamType::TIME, [1000, 1010, 1020]);

        $elevationService = $this->createStub(OpenMeteoElevation::class);
        $elevationService->method('getElevations')->willReturn([100.0, 110.0, 130.0]);

        $terrainTiles = new SpyTerrainTilesElevation();
        $terrainTiles->setDefaultElevation(100.0);

        $metrics = $this->provideRecalculator($elevationService, $terrainTiles)
            ->recalculate($activity, [RecalculationType::ELEVATION]);

        // The first source gains 30 meters, the second one does not gain anything.
        $this->assertEqualsWithDelta(15.0, $metrics->getElevation()?->toFloat() ?? 0, 0.001);
        $this->assertNull($metrics->getDistance());
    }

    public function testItAveragesTheElevationOfTheSources(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::LAT_LNG, [
            [50.0, 4.0],
            [50.1, 4.0],
        ]);
        $this->provideStream(StreamType::TIME, [1000, 1010]);

        $elevationService = $this->createStub(OpenMeteoElevation::class);
        $elevationService->method('getElevations')->willReturn([100.0, 200.0]);

        $terrainTiles = new SpyTerrainTilesElevation();
        $terrainTiles->setDefaultElevation(0.0);

        $metrics = $this->provideRecalculator($elevationService, $terrainTiles)
            ->recalculate($activity, [RecalculationType::ELEVATION]);

        // The first source gains 100 meters, the second one gains 0 meters.
        $this->assertEqualsWithDelta(50.0, $metrics->getElevation()?->toFloat() ?? 0, 0.001);
    }

    public function testItThrowsWhenTheElevationServiceReturnsNothing(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::LAT_LNG, [[50.0, 4.0]]);
        $this->provideStream(StreamType::TIME, [1000]);

        // Neither source returns an elevation.
        $elevationService = $this->createStub(OpenMeteoElevation::class);
        $elevationService->method('getElevations')->willReturn([null]);
        $terrainTiles = $this->createStub(TerrainTilesElevation::class);
        $terrainTiles->method('getElevations')->willReturn([null]);

        $this->expectException(CouldNotRecalculateActivityMetrics::class);
        $this->provideRecalculator($elevationService, $terrainTiles)->recalculate($activity, [RecalculationType::ELEVATION]);
    }

    public function testItDetectsThePausesUsingTheSportThreshold(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::TIME, [0, 10, 20, 30, 40, 50, 60]);
        // The athlete rides for 20 seconds, stops for 20 seconds and rides again for 20 seconds.
        $this->provideStream(StreamType::DISTANCE, [0, 20, 40, 40, 40, 60, 80]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::TIME]);

        $this->assertSame(40, $metrics->getMovingTimeInSeconds());
        $this->assertSame(60, $metrics->getElapsedTimeInSeconds());
    }

    public function testItTreatsSlowSegmentsAsAPauseForRunning(): void
    {
        $activity = $this->provideActivity(sportType: SportType::RUN);
        $this->provideStream(StreamType::TIME, [0, 10, 20, 30]);
        // 0.2 meters per second is below the running threshold of 0.5 meters per second.
        $this->provideStream(StreamType::DISTANCE, [0, 2, 4, 6]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::TIME]);

        $this->assertSame(0, $metrics->getMovingTimeInSeconds());
        $this->assertSame(30, $metrics->getElapsedTimeInSeconds());
    }

    public function testItIgnoresRecordingGaps(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::TIME, [0, 10, 500, 510]);
        $this->provideStream(StreamType::DISTANCE, [0, 20, 40, 60]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::TIME]);

        // The gap of 490 seconds is not active time, only the two 10 second intervals are.
        $this->assertSame(20, $metrics->getMovingTimeInSeconds());
        $this->assertSame(510, $metrics->getElapsedTimeInSeconds());
    }

    public function testItCalculatesTheAverageSpeedFromTheDistanceAndMovingTime(): void
    {
        $activity = $this->provideActivity();
        // An hour of riding, sampled every 10 seconds.
        $times = [];
        $distances = [];
        for ($second = 0; $second <= 3600; $second += 10) {
            $times[] = $second;
            $distances[] = $second * (20000 / 3600);
        }
        $this->provideStream(StreamType::TIME, $times);
        $this->provideStream(StreamType::DISTANCE, $distances);
        $this->provideStream(StreamType::LAT_LNG, [[50.0, 4.0], [50.18, 4.0]]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::TIME, RecalculationType::DISTANCE]);

        $this->assertSame(3600, $metrics->getMovingTimeInSeconds());
        $this->assertEqualsWithDelta(20.0, $metrics->getAverageSpeed()?->toFloat() ?? 0, 0.2);
    }

    public function testItRecalculatesTheAverageSpeedWhenOnlyTheDistanceChanges(): void
    {
        $activity = $this->provideActivity();
        $times = [];
        $distances = [];
        for ($second = 0; $second <= 3600; $second += 10) {
            $times[] = $second;
            $distances[] = $second * (20000 / 3600);
        }
        $this->provideStream(StreamType::TIME, $times);
        $this->provideStream(StreamType::DISTANCE, $distances);
        $this->provideStream(StreamType::LAT_LNG, [[50.0, 4.0], [50.18, 4.0]]);

        $metrics = $this->provideRecalculator()->recalculate($activity, [RecalculationType::DISTANCE]);

        $this->assertNotNull($metrics->getAverageSpeed());
        $this->assertNull($metrics->getMovingTimeInSeconds());
    }

    public function testItThrowsWhenThereIsNoCoordinateData(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::TIME, [0, 10]);

        $this->expectException(CouldNotRecalculateActivityMetrics::class);
        $this->provideRecalculator()->recalculate($activity, [RecalculationType::DISTANCE]);
    }

    public function testItCachesTheElevationsSoTheServiceIsOnlyCalledOnce(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::LAT_LNG, [[50.0, 4.0], [50.001, 4.0]]);
        $this->provideStream(StreamType::TIME, [1000, 1010]);

        $elevationService = $this->createMock(OpenMeteoElevation::class);
        $elevationService->expects($this->once())
            ->method('getElevations')
            ->willReturn([100.0, 110.0]);

        // The cache is stored in the KeyValue table, a second recalculation reuses it.
        $stored = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            function () use (&$stored): string|false {
                return $stored['value'] ?? false;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use (&$stored): int {
                $stored['value'] = $parameters['value'];

                return 1;
            }
        );

        $repository = $this->createStub(ActivityStreamRepository::class);
        $repository->method('findByActivityId')->willReturn(ActivityStreams::fromArray($this->streams));

        $recalculator = new ActivityMetricsRecalculator(
            activityStreamRepository: $repository,
            openMeteoElevation: $elevationService,
            terrainTilesElevation: $terrainTilesElevation ?? new SpyTerrainTilesElevation(),
            elevationGainCalculator: new ElevationGainCalculator(),
            connection: $connection,
        );

        $first = $recalculator->recalculate($activity, [RecalculationType::ELEVATION]);
        $second = $recalculator->recalculate($activity, [RecalculationType::ELEVATION]);

        // The points are 111 meters apart, so the smoothing window covers both of them.
        $this->assertEqualsWithDelta(3.0, $first->getElevation()?->toFloat() ?? 0, 0.001);
        $this->assertEqualsWithDelta(3.0, $second->getElevation()?->toFloat() ?? 0, 0.001);
    }

    public function testItThrowsWhenThereIsNoTimeData(): void
    {
        $activity = $this->provideActivity();
        $this->provideStream(StreamType::LAT_LNG, [[50.0, 4.0], [50.01, 4.0]]);

        $this->expectException(CouldNotRecalculateActivityMetrics::class);
        $this->provideRecalculator()->recalculate($activity, [RecalculationType::TIME]);
    }

    private function provideActivity(SportType $sportType = SportType::RIDE): Activity
    {
        return ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withSportType($sportType)
            ->withMovingTimeInSeconds(3600)
            ->withElapsedTimeInSeconds(3600)
            ->build();
    }

    /**
     * @param list<mixed> $data
     */
    private function provideStream(StreamType $streamType, array $data): void
    {
        $this->streams[] = ActivityStream::create(
            activityId: ActivityId::fromUnprefixed('1'),
            streamType: $streamType,
            streamData: $data,
            createdOn: SerializableDateTime::fromString('2023-01-01 00:00:00'),
        );
    }

    private function provideRecalculator(
        ?OpenMeteoElevation $elevationService = null,
        ?TerrainTilesElevation $terrainTilesElevation = null,
    ): ActivityMetricsRecalculator {
        $repository = $this->createStub(ActivityStreamRepository::class);
        $repository->method('findByActivityId')->willReturn(ActivityStreams::fromArray($this->streams));

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        return new ActivityMetricsRecalculator(
            activityStreamRepository: $repository,
            openMeteoElevation: $elevationService ?? new SpyOpenMeteoElevation(),
            terrainTilesElevation: $terrainTilesElevation ?? new SpyTerrainTilesElevation(),
            elevationGainCalculator: new ElevationGainCalculator(),
            connection: $connection,
        );
    }
}
