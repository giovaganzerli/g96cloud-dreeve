<?php

declare(strict_types=1);

namespace App\Domain\Integration\Elevation\OpenMeteo;

final class OpenMeteoElevationApiCallHasFailed extends \RuntimeException
{
    public static function becauseTheServiceIsUnavailable(?\Throwable $previous = null): self
    {
        return new self(
            sprintf('The elevation service is unavailable (%s)', $previous?->getMessage() ?? 'unknown error'),
            previous: $previous
        );
    }

    public static function becauseTheRateLimitIsReached(): self
    {
        return new self('The elevation service rate limit is reached, try again in a minute');
    }

    public static function becauseTheResponseIsInvalid(): self
    {
        return new self('The elevation service returned an invalid response');
    }
}
