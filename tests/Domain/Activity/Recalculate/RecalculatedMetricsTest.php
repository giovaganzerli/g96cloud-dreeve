<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Recalculate;

use App\Domain\Activity\RecalculatedMetrics;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use PHPUnit\Framework\TestCase;

class RecalculatedMetricsTest extends TestCase
{
    public function testItKeepsTheOriginalValuesWhenMerging(): void
    {
        $originals = new RecalculatedMetrics(
            originalBestEfforts: [['distanceInMeter' => 1000, 'timeInSeconds' => 300, 'sportType' => 'Ride']],
            originalSegmentEfforts: [['segmentEffortId' => 'one', 'segmentId' => 'two', 'startDateTime' => '2023-01-01 00:00:00', 'elapsedTimeInSeconds' => 60.0, 'distance' => 1.0, 'averageWatts' => null, 'averageHeartRate' => null, 'maxHeartRate' => null]],
        );

        $merged = $originals->merge(new RecalculatedMetrics(distance: Kilometer::from(12)));

        $this->assertEqualsWithDelta(12.0, $merged->getDistance()?->toFloat() ?? 0, 0.001);
        // The backup of the original values has to survive a recalculation.
        $this->assertCount(1, $merged->getOriginalBestEfforts() ?? []);
        $this->assertCount(1, $merged->getOriginalSegmentEfforts() ?? []);
    }

    public function testItSerializesAndRestoresTheOriginalValues(): void
    {
        $metrics = new RecalculatedMetrics(
            distance: Kilometer::from(12.5),
            elevation: Meter::from(150),
            movingTimeInSeconds: 3600,
            elapsedTimeInSeconds: 3900,
            averageSpeed: KmPerHour::from(12.5),
            originalBestEfforts: [['distanceInMeter' => 1000, 'timeInSeconds' => 300, 'sportType' => 'Ride']],
        );

        $restored = RecalculatedMetrics::fromArray($metrics->jsonSerialize());

        $this->assertEqualsWithDelta(12.5, $restored->getDistance()?->toFloat() ?? 0, 0.001);
        $this->assertEqualsWithDelta(150.0, $restored->getElevation()?->toFloat() ?? 0, 0.001);
        $this->assertSame(3600, $restored->getMovingTimeInSeconds());
        $this->assertSame(3900, $restored->getElapsedTimeInSeconds());
        $this->assertCount(1, $restored->getOriginalBestEfforts() ?? []);
        $this->assertNull($restored->getOriginalSegmentEfforts());
    }

    public function testItRejectsAnInvalidBackup(): void
    {
        $restored = RecalculatedMetrics::fromArray(['originalBestEfforts' => [['distanceInMeter' => 'not a number']]]);

        // An invalid backup is ignored, so a revert can never restore garbage.
        $this->assertNull($restored->getOriginalBestEfforts());
    }
}
