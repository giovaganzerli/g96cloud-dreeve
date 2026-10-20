<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Recalculate;

use App\Domain\Activity\Recalculate\PauseDetectionParameters;
use App\Domain\Activity\SportType\SportType;
use PHPUnit\Framework\TestCase;

class PauseDetectionParametersTest extends TestCase
{
    public function testItUsesASportSpecificThreshold(): void
    {
        $road = PauseDetectionParameters::for(SportType::RIDE);
        $mountainBike = PauseDetectionParameters::for(SportType::MOUNTAIN_BIKE_RIDE);
        $running = PauseDetectionParameters::for(SportType::RUN);
        $trailRunning = PauseDetectionParameters::for(SportType::TRAIL_RUN);
        $hiking = PauseDetectionParameters::for(SportType::HIKE);

        // Technical sections are ridden slower, so the threshold has to be lower to
        // avoid counting them as a pause.
        $this->assertLessThan($road->getThresholdInMetersPerSecond(), $mountainBike->getThresholdInMetersPerSecond());
        $this->assertLessThan($running->getThresholdInMetersPerSecond(), $trailRunning->getThresholdInMetersPerSecond());
        // Hiking involves longer stops than a run.
        $this->assertGreaterThan($running->getMinimumPauseDurationInSeconds(), $hiking->getMinimumPauseDurationInSeconds());
    }

    public function testItCoversEverySportType(): void
    {
        foreach (SportType::cases() as $sportType) {
            $parameters = PauseDetectionParameters::for($sportType);

            $this->assertGreaterThan(0.0, $parameters->getThresholdInMetersPerSecond());
            $this->assertGreaterThan(0, $parameters->getMinimumPauseDurationInSeconds());
        }
    }
}
