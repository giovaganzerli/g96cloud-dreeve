<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Recalculate;

use App\Domain\Activity\Recalculate\ElevationGainCalculator;
use PHPUnit\Framework\TestCase;

class ElevationGainCalculatorTest extends TestCase
{
    public function testItReturnsZeroForAnEmptySeries(): void
    {
        $this->assertSame(0.0, (new ElevationGainCalculator())->calculate([], []));
    }

    public function testItSumsTheClimbs(): void
    {
        $elevations = [100.0, 110.0, 105.0, 130.0];
        $distances = [0.0, 1000.0, 2000.0, 3000.0];

        // 10 meters up, 5 meters down and 25 meters up.
        $this->assertSame(35.0, (new ElevationGainCalculator())->calculate($elevations, $distances));
    }

    public function testItIgnoresHeightDifferencesBelowTheThreshold(): void
    {
        $elevations = [100.0, 100.5, 101.0, 101.4];
        $distances = [0.0, 1000.0, 2000.0, 3000.0];

        $this->assertSame(0.0, (new ElevationGainCalculator())->calculate($elevations, $distances));
    }

    public function testItSmoothsTheSeriesOverAWindowInMeters(): void
    {
        // A single spike of 10 meters, with points every 10 meters.
        $elevations = [100.0, 100.0, 110.0, 100.0, 100.0];
        $distances = [0.0, 10.0, 20.0, 30.0, 40.0];
        $calculator = new ElevationGainCalculator();

        // With a narrow window every point stands on its own, the spike is a climb.
        $narrow = $calculator->calculate($elevations, $distances, 5.0);
        // With a wide window the spike is averaged out over the whole track.
        $wide = $calculator->calculate($elevations, $distances, 150.0);

        $this->assertSame(10.0, $narrow);
        $this->assertLessThan($narrow, $wide);
    }

    public function testItReturnsAlmostTheSameResultRegardlessOfTheSampleDensity(): void
    {
        $calculator = new ElevationGainCalculator();

        // The same constant climb, sampled every 10 meters and every 40 meters.
        $denseElevations = [];
        $denseDistances = [];
        $sparseElevations = [];
        $sparseDistances = [];
        for ($i = 0; $i <= 40; ++$i) {
            $denseElevations[] = 100.0 + $i * 0.5;
            $denseDistances[] = $i * 10.0;
        }
        for ($i = 0; $i <= 10; ++$i) {
            $sparseElevations[] = 100.0 + $i * 2.0;
            $sparseDistances[] = $i * 40.0;
        }

        $dense = $calculator->calculate($denseElevations, $denseDistances);
        $sparse = $calculator->calculate($sparseElevations, $sparseDistances);

        // Both climb 20 meters. The smoothing window makes the calculation lag behind
        // a bit, so the last part of the climb is not counted yet.
        $this->assertEqualsWithDelta(20.0, $dense, 4.0);
        $this->assertEqualsWithDelta(20.0, $sparse, 4.0);
    }
}
