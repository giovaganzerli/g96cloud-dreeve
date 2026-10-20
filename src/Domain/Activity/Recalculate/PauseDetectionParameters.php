<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Domain\Activity\SportType\SportType;

/**
 * The parameters used to detect the pauses of an activity.
 *
 * The minimum speed below which the athlete is considered to be paused and the
 * minimum duration of a pause before it actually counts as one. A short slowdown,
 * like rolling up to a traffic light, is not a pause.
 */
final readonly class PauseDetectionParameters
{
    private function __construct(
        private float $thresholdInMetersPerSecond,
        private int $minimumPauseDurationInSeconds,
    ) {
    }

    public static function for(SportType $sportType): self
    {
        return match ($sportType) {
            // Cycling on the road: the athlete stops at traffic lights and crossings.
            SportType::RIDE, SportType::E_BIKE_RIDE, SportType::VIRTUAL_RIDE, SportType::VELO_MOBILE => new self(0.5, 20),
            // Gravel: more technical sections, but the stops are the same as on the road.
            SportType::GRAVEL_RIDE => new self(0.4, 20),
            // Mountain biking: technical sections where the speed drops a lot.
            SportType::MOUNTAIN_BIKE_RIDE, SportType::E_MOUNTAIN_BIKE_RIDE => new self(0.3, 15),
            SportType::HAND_CYCLE, SportType::WHEELCHAIR => new self(0.4, 20),
            // Running: the stops are short, mostly traffic lights.
            SportType::RUN, SportType::VIRTUAL_RUN => new self(0.5, 15),
            // Trail running: technical sections where the speed drops a lot.
            SportType::TRAIL_RUN => new self(0.3, 15),
            // Walking and hiking: frequent and longer stops.
            SportType::WALK => new self(0.3, 20),
            SportType::HIKE => new self(0.3, 30),
            // Water sports.
            SportType::CANOEING, SportType::KAYAKING, SportType::STAND_UP_PADDLING,
            SportType::ROWING, SportType::VIRTUAL_ROW, SportType::SURFING,
            SportType::WIND_SURF, SportType::KITE_SURF, SportType::WAKEBOARDING => new self(0.3, 20),
            SportType::POOL_SWIM, SportType::OPEN_WATER_SWIM => new self(0.2, 20),
            // Winter sports.
            SportType::BACK_COUNTRY_SKI, SportType::ALPINE_SKI, SportType::NORDIC_SKI,
            SportType::SNOWBOARD, SportType::SNOWSHOE => new self(0.3, 20),
            // Skating.
            SportType::ICE_SKATE, SportType::SKATEBOARD, SportType::INLINE_SKATE, SportType::ROLLER_SKI => new self(0.3, 15),
            // Racquet and paddle sports: the athlete moves in short bursts.
            SportType::BADMINTON, SportType::PICKLE_BALL, SportType::RACQUET_BALL, SportType::SQUASH,
            SportType::TABLE_TENNIS, SportType::TENNIS, SportType::PADEL => new self(0.2, 15),
            // Indoor sports, the speed comes from the device, not from GPS.
            SportType::CROSSFIT, SportType::WEIGHT_TRAINING, SportType::WORKOUT, SportType::STAIR_STEPPER,
            SportType::HIIT, SportType::ELLIPTICAL, SportType::DANCE,
            SportType::PILATES, SportType::YOGA, SportType::PHYSICAL_THERAPY, SportType::GOLF => new self(0.2, 20),
            // Outdoor adventure sports.
            SportType::ROCK_CLIMBING, SportType::SAIL => new self(0.3, 20),
            // Team sports.
            SportType::BASKETBALL, SportType::SOCCER, SportType::VOLLEYBALL, SportType::CRICKET => new self(0.2, 20),
        };
    }

    public function getThresholdInMetersPerSecond(): float
    {
        return $this->thresholdInMetersPerSecond;
    }

    public function getMinimumPauseDurationInSeconds(): int
    {
        return $this->minimumPauseDurationInSeconds;
    }
}
