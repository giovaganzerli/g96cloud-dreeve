<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

final class CouldNotRecalculateActivityMetrics extends \RuntimeException
{
    public static function becauseThereIsNoCoordinateData(): self
    {
        return new self('This activity has no GPS data, so the distance and elevation cannot be recalculated');
    }

    public static function becauseThereIsNoTimeData(): self
    {
        return new self('This activity has no time data, so the times cannot be recalculated');
    }

    public static function becauseThereIsNoDistanceData(): self
    {
        return new self('This activity has no distance data, so the pauses cannot be detected');
    }

    public static function becauseTheElevationIsUnavailable(): self
    {
        return new self('The elevation service did not return any data for this activity');
    }
}
