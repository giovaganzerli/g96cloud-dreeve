<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\Statistics;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\Statistics\CollectionStatistic;
use App\Domain\Collection\Statistics\CollectionStatisticsCalculator;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Collection\CollectionBuilder;

class CollectionStatisticsCalculatorTest extends ContainerTestCase
{
    private CollectionStatisticsCalculator $statisticsCalculator;

    public function testItCalculatesTheStatisticsOfTheActivitiesInTheCollection(): void
    {
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);

        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning ride')
                ->withDistance(Kilometer::from(20))
                ->withElevation(Meter::from(300))
                ->withMovingTimeInSeconds(3600)
                ->withElapsedTimeInSeconds(3900)
                ->withAverageSpeed(KmPerHour::from(20))
                ->build(),
            []
        ));
        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('2'))
                ->withName('Evening ride')
                ->withDistance(Kilometer::from(30))
                ->withElevation(Meter::from(100))
                ->withMovingTimeInSeconds(3600)
                ->withElapsedTimeInSeconds(4200)
                ->withAverageSpeed(KmPerHour::from(30))
                ->build(),
            []
        ));

        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withActivityId(ActivityId::fromUnprefixed('2'))
            ->build();

        $activities = $this->getContainer()->get(\App\Domain\Activity\EnrichedActivityRepository::class)
            ->findByIds($collection->getActivityIds());

        $statistics = $this->statisticsCalculator->calculateAutomatic($activities);

        $this->assertEquals('50.0', $statistics->findByKey(CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE)?->getValue());
        $this->assertEquals('25.0', $statistics->findByKey(CollectionStatisticsCalculator::KEY_AVERAGE_DISTANCE)?->getValue());
        $this->assertEquals('02:15:00', $statistics->findByKey(CollectionStatisticsCalculator::KEY_TOTAL_TIME)?->getValue());
        $this->assertEquals('02:00:00', $statistics->findByKey(CollectionStatisticsCalculator::KEY_MOVING_TIME)?->getValue());
        $this->assertEquals('00:15:00', $statistics->findByKey(CollectionStatisticsCalculator::KEY_STOPPED_TIME)?->getValue());
        $this->assertEquals('400', $statistics->findByKey(CollectionStatisticsCalculator::KEY_TOTAL_ASCENT)?->getValue());
        $this->assertEquals('25.0', $statistics->findByKey(CollectionStatisticsCalculator::KEY_AVERAGE_SPEED)?->getValue());
    }

    public function testItCalculatesTheMaxSpeed(): void
    {
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withAverageSpeed(KmPerHour::from(20))
                ->withMaxSpeed(KmPerHour::from(52.5))
                ->build(),
            []
        ));

        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $activities = $this->getContainer()->get(\App\Domain\Activity\EnrichedActivityRepository::class)
            ->findByIds($collection->getActivityIds());

        $statistics = $this->statisticsCalculator->calculateAutomatic($activities);

        $this->assertEquals('52.5', $statistics->findByKey(CollectionStatisticsCalculator::KEY_MAX_SPEED)?->getValue());
    }

    public function testTheStageStatisticsLinkToTheirActivity(): void
    {
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('The long one')
                ->withDistance(Kilometer::from(42))
                ->build(),
            []
        ));

        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $activities = $this->getContainer()->get(\App\Domain\Activity\EnrichedActivityRepository::class)
            ->findByIds($collection->getActivityIds());

        $statistics = $this->statisticsCalculator->calculateAutomatic($activities);

        $this->assertEquals(
            '1',
            $statistics->findByKey(CollectionStatisticsCalculator::KEY_LONGEST_STAGE)?->getLinkActivityId()?->toUnprefixedString(),
        );
        $this->assertEquals(
            '1',
            $statistics->findByKey(CollectionStatisticsCalculator::KEY_STAGE_WITH_MOST_ASCENT)?->getLinkActivityId()?->toUnprefixedString(),
        );
        $this->assertNull(
            $statistics->findByKey(CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE)?->getLinkActivityId(),
        );
    }

    public function testItAppliesTheManuallyEditedValues(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withStatisticOverrides([CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE => 'a lot'])
            ->build();

        $statistics = $this->statisticsCalculator->calculate($collection, []);

        $totalDistance = $statistics->findByKey(CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE);
        $this->assertEquals('a lot', $totalDistance?->getValue());
        $this->assertTrue($totalDistance?->isManuallyEdited());
    }

    public function testItAppliesTheConfiguredOrder(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withStatisticOrder([
                CollectionStatisticsCalculator::KEY_AVERAGE_SPEED,
                CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE,
            ])
            ->build();

        $statistics = $this->statisticsCalculator->calculate($collection, []);

        $this->assertEquals(
            CollectionStatisticsCalculator::KEY_AVERAGE_SPEED,
            $statistics->getFirst()?->getKey(),
        );
        $this->assertEquals(
            CollectionStatisticsCalculator::KEY_TOTAL_DISTANCE,
            $statistics->toArray()[1]->getKey(),
        );
    }

    public function testItAddsTheCustomStatistics(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withCustomStatistic('cost', 'Travel cost', '€ 120', '€')
            ->build();

        $statistics = $this->statisticsCalculator->calculate($collection, []);

        $customStatistic = $statistics->findByKey('custom.cost');
        $this->assertInstanceOf(CollectionStatistic::class, $customStatistic);
        $this->assertEquals('Travel cost', $customStatistic->getLabel());
        $this->assertEquals('€ 120', $customStatistic->getValue());
        $this->assertTrue($customStatistic->isCustom());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->statisticsCalculator = $this->getContainer()->get(CollectionStatisticsCalculator::class);
    }
}
