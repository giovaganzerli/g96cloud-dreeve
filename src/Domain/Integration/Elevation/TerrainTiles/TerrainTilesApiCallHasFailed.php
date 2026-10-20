<?php

declare(strict_types=1);

namespace App\Domain\Integration\Elevation\TerrainTiles;

final class TerrainTilesApiCallHasFailed extends \RuntimeException
{
    public static function becauseTheServiceIsUnavailable(?\Throwable $previous = null): self
    {
        return new self(
            sprintf('The elevation tiles service is unavailable (%s)', $previous?->getMessage() ?? 'unknown error'),
            previous: $previous
        );
    }
}
