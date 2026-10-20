<?php

declare(strict_types=1);

namespace App\Domain\Integration\Elevation\OpenMeteo;

interface OpenMeteoElevation
{
    public const int MAX_COORDINATES_PER_REQUEST = 100;

    /**
     * Look up the elevation (in meters above sea level) for the given coordinates.
     *
     * @param list<array{float, float}> $coordinates
     *
     * @return list<float|null> the elevation for every coordinate, null when it could not be determined
     */
    public function getElevations(array $coordinates): array;
}
