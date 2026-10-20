<?php

declare(strict_types=1);

namespace App\Tests\Domain\Integration\Elevation\OpenMeteo;

use App\Domain\Integration\Elevation\OpenMeteo\OpenMeteoElevation;
use App\Domain\Integration\Elevation\OpenMeteo\OpenMeteoElevationApiCallHasFailed;

class SpyOpenMeteoElevation implements OpenMeteoElevation
{
    private bool $triggerExceptionOnNextCall = false;

    /** @var array<string, float> */
    private array $elevationsByLatitude = [];

    private ?float $defaultElevation = 100.0;

    public function getElevations(array $coordinates): array
    {
        if ($this->triggerExceptionOnNextCall) {
            $this->triggerExceptionOnNextCall = false;
            throw OpenMeteoElevationApiCallHasFailed::becauseTheServiceIsUnavailable();
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
