<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity;

use App\Domain\Activity\RecalculatedMetrics;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Velocity\KmPerHour;
use PHPUnit\Framework\TestCase;

class RecalculatedMetricsTest extends TestCase
{
    public function testItIsEmptyWhenNothingIsRecalculated(): void
    {
        $this->assertTrue((new RecalculatedMetrics())->isEmpty());
        $this->assertFalse((new RecalculatedMetrics(distance: Kilometer::from(1)))->isEmpty());
    }

    public function testItSerializesAndDeserializes(): void
    {
        $metrics = new RecalculatedMetrics(
            distance: Kilometer::from(12.5),
            elevation: Meter::from(150),
            movingTimeInSeconds: 3600,
            elapsedTimeInSeconds: 3900,
            averageSpeed: KmPerHour::from(12.5),
        );

        $serialized = $metrics->jsonSerialize();
        $this->assertSame(12.5, $serialized['distance']);
        $this->assertSame(150.0, $serialized['elevation']);
        $this->assertSame(3600, $serialized['movingTimeInSeconds']);
        $this->assertSame(3900, $serialized['elapsedTimeInSeconds']);
        $this->assertSame(12.5, $serialized['averageSpeed']);

        $restored = RecalculatedMetrics::fromArray($serialized);
        $this->assertEqualsWithDelta(12.5, $restored->getDistance()?->toFloat() ?? 0, 0.001);
        $this->assertEqualsWithDelta(150.0, $restored->getElevation()?->toFloat() ?? 0, 0.001);
        $this->assertSame(3600, $restored->getMovingTimeInSeconds());
        $this->assertSame(3900, $restored->getElapsedTimeInSeconds());
        $this->assertEqualsWithDelta(12.5, $restored->getAverageSpeed()?->toFloat() ?? 0, 0.001);
    }

    public function testItDeserializesAnEmptyPayload(): void
    {
        $metrics = RecalculatedMetrics::fromArray([]);

        $this->assertTrue($metrics->isEmpty());
        $this->assertNull($metrics->getDistance());
        $this->assertNull($metrics->getElevation());
        $this->assertNull($metrics->getMovingTimeInSeconds());
        $this->assertNull($metrics->getElapsedTimeInSeconds());
        $this->assertNull($metrics->getAverageSpeed());
    }

    public function testItMergesTheRecalculatedValues(): void
    {
        $existing = new RecalculatedMetrics(
            distance: Kilometer::from(10),
            elevation: Meter::from(100),
            movingTimeInSeconds: 3600,
            averageSpeed: KmPerHour::from(10),
        );

        $merged = $existing->merge(new RecalculatedMetrics(distance: Kilometer::from(12)));

        // The values that were not recalculated keep their previous value.
        $this->assertEqualsWithDelta(12.0, $merged->getDistance()?->toFloat() ?? 0, 0.001);
        $this->assertEqualsWithDelta(100.0, $merged->getElevation()?->toFloat() ?? 0, 0.001);
        $this->assertSame(3600, $merged->getMovingTimeInSeconds());
        $this->assertEqualsWithDelta(10.0, $merged->getAverageSpeed()?->toFloat() ?? 0, 0.001);
    }
}
