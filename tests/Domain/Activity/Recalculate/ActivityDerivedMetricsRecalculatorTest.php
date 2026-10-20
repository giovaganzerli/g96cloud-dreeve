<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Recalculate;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\BestEffort\ActivityBestEffort;
use App\Domain\Activity\BestEffort\ActivityBestEffortCalculator;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\BestEffort\ActivityBestEfforts;
use App\Domain\Activity\Recalculate\ActivityDerivedMetricsRecalculator;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Segment\SegmentEffort\Matching\SegmentEffortMatcher;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentEffort\SegmentEfforts;
use App\Domain\Segment\SegmentRepository;
use App\Domain\Segment\Segments;
use App\Infrastructure\Measurement\Length\Meter;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\TestCase;

class ActivityDerivedMetricsRecalculatorTest extends TestCase
{
    public function testItBacksUpAndRestoresTheBestEfforts(): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $stored = ActivityBestEffort::create(
            activityId: ActivityId::fromUnprefixed('1'),
            distanceInMeter: Meter::from(1000),
            sportType: SportType::RIDE,
            timeInSeconds: 300,
        );

        $bestEffortRepository = $this->createStub(ActivityBestEffortRepository::class);
        $bestEffortRepository->method('findAllByActivity')->willReturn(ActivityBestEfforts::fromArray([$stored]));

        $added = [];
        $bestEffortRepository->method('add')->willReturnCallback(
            static function (ActivityBestEffort $bestEffort) use (&$added): void {
                $added[] = $bestEffort;
            }
        );

        $recalculator = $this->provideRecalculator($bestEffortRepository);

        $originals = $recalculator->backupBestEfforts($activity->getId());

        $this->assertSame(
            [['distanceInMeter' => 1000, 'timeInSeconds' => 300, 'sportType' => 'Ride']],
            $originals
        );

        // Restoring puts the original values back.
        $recalculator->restoreBestEfforts($activity, $originals);
        $this->assertCount(1, $added);
        $this->assertSame(1000, $added[0]->getDistanceInMeter()->toInt());
        $this->assertSame(300, $added[0]->getTimeInSeconds());
        $this->assertSame(SportType::RIDE, $added[0]->getSportType());
    }

    public function testItAppliesTheRecalculatedBestEfforts(): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $stored = ActivityBestEffort::create(
            activityId: ActivityId::fromUnprefixed('1'),
            distanceInMeter: Meter::from(1000),
            sportType: SportType::RIDE,
            timeInSeconds: 300,
        );

        $bestEffortRepository = $this->createStub(ActivityBestEffortRepository::class);
        $bestEffortRepository->method('findAllByActivity')->willReturn(ActivityBestEfforts::fromArray([$stored]));

        $added = [];
        $bestEffortRepository->method('add')->willReturnCallback(
            static function (ActivityBestEffort $bestEffort) use (&$added): void {
                $added[] = $bestEffort;
            }
        );

        $recalculator = $this->provideRecalculator($bestEffortRepository);

        // The recalculated 1 km best effort replaces the current one.
        $recalculator->applyBestEfforts($activity, [
            ['distanceInMeter' => 1000, 'timeInSeconds' => 280, 'sportType' => 'Ride'],
        ]);

        $this->assertCount(1, $added);
        $this->assertSame(280, $added[0]->getTimeInSeconds());
    }

    public function testItKeepsTheBestEffortsWhenThereIsNothingToRecalculate(): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $stored = ActivityBestEffort::create(
            activityId: ActivityId::fromUnprefixed('1'),
            distanceInMeter: Meter::from(1000),
            sportType: SportType::RIDE,
            timeInSeconds: 300,
        );

        $bestEffortRepository = $this->createStub(ActivityBestEffortRepository::class);
        $bestEffortRepository->method('findAllByActivity')->willReturn(ActivityBestEfforts::fromArray([$stored]));

        $added = [];
        $bestEffortRepository->method('add')->willReturnCallback(
            static function (ActivityBestEffort $bestEffort) use (&$added): void {
                $added[] = $bestEffort;
            }
        );

        $recalculator = $this->provideRecalculator($bestEffortRepository);

        // Without a recalculation the stored best efforts are left untouched.
        $recalculator->applyBestEfforts($activity, []);

        $this->assertCount(0, $added);
    }

    public function testItBacksUpTheSegmentEfforts(): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->build();

        $segmentEffortRepository = $this->createStub(SegmentEffortRepository::class);
        $segmentEffortRepository->method('findByActivityId')->willReturn(SegmentEfforts::empty());

        $recalculator = $this->provideRecalculator(segmentEffortRepository: $segmentEffortRepository);

        $this->assertSame([], $recalculator->backupSegmentEfforts($activity->getId()));
    }

    private function provideRecalculator(
        ?ActivityBestEffortRepository $bestEffortRepository = null,
        ?SegmentEffortRepository $segmentEffortRepository = null,
    ): ActivityDerivedMetricsRecalculator {
        $activityRepository = $this->createStub(ActivityRepository::class);
        $activityRepository->method('find')->willReturn(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->build()
        );

        $streamRepository = $this->createStub(ActivityStreamRepository::class);
        $streamRepository->method('findByActivityId')->willReturn(\App\Domain\Activity\Stream\ActivityStreams::empty());

        $segmentRepository = $this->createStub(SegmentRepository::class);
        $segmentRepository->method('findByType')->willReturn(Segments::empty());

        return new ActivityDerivedMetricsRecalculator(
            activityBestEffortRepository: $bestEffortRepository ?? $this->createStub(ActivityBestEffortRepository::class),
            segmentEffortRepository: $segmentEffortRepository ?? $this->createStub(SegmentEffortRepository::class),
            segmentRepository: $segmentRepository,
            activityStreamRepository: $streamRepository,
            bestEffortCalculator: new ActivityBestEffortCalculator(
                activityRepository: $activityRepository,
                activityBestEffortRepository: $bestEffortRepository ?? $this->createStub(ActivityBestEffortRepository::class),
                activityStreamRepository: $streamRepository,
            ),
            segmentEffortMatcher: new SegmentEffortMatcher(),
        );
    }
}
