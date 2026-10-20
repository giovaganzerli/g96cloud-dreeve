<?php

declare(strict_types=1);

namespace App\Tests\Domain\Integration\Elevation\TerrainTiles;

use App\Domain\Integration\Elevation\TerrainTiles\TerrainTilesApiCallHasFailed;
use App\Domain\Integration\Elevation\TerrainTiles\TerrainTilesElevation;

class SpyTerrainTilesElevation implements TerrainTilesElevation
{
    private bool $triggerExceptionOnNextCall = false;

    private ?float $defaultElevation = 100.0;

    /** @var array<string, float> */
    private array $elevationsByLatitude = [];

    public function getElevations(array $coordinates): array
    {
        if ($this->triggerExceptionOnNextCall) {
            $this->triggerExceptionOnNextCall = false;
            throw TerrainTilesApiCallHasFailed::becauseTheServiceIsUnavailable();
        }

        return array_map(
            function (array $coordinate): ?float {
                return $this->elevationsByLatitude[(string) $coordinate[0]] ?? $this->defaultElevation;
            },
            $coordinates
        );
    }

    public function setElevationForLatitude(float $latitude, float $elevation): void
    {
        $this->elevationsByLatitude[(string) $latitude] = $elevation;
    }

    public function setDefaultElevation(?float $elevation): void
    {
        $this->defaultElevation = $elevation;
    }

    public function triggerExceptionOnNextCall(): void
    {
        $this->triggerExceptionOnNextCall = true;
    }
}
