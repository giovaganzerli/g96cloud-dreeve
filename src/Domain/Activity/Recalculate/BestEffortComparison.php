<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

use App\Infrastructure\Measurement\Length\Meter;

/**
 * A best effort of an activity, with the value it has now and the recalculated one.
 */
final readonly class BestEffortComparison
{
    public function __construct(
        private Meter $distance,
        private ?int $currentTimeInSeconds,
        private ?int $recalculatedTimeInSeconds,
    ) {
    }

    public function getDistance(): Meter
    {
        return $this->distance;
    }

    public function getCurrentTimeInSeconds(): ?int
    {
        return $this->currentTimeInSeconds;
    }

    public function getRecalculatedTimeInSeconds(): ?int
    {
        return $this->recalculatedTimeInSeconds;
    }

    /**
     * A recalculated value that is the same as the current one is not a change.
     */
    public function hasDifference(): bool
    {
        return null !== $this->recalculatedTimeInSeconds
            && $this->recalculatedTimeInSeconds !== $this->currentTimeInSeconds;
    }
}
