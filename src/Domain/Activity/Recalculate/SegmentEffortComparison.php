<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

/**
 * A segment of an activity, with the time it has now and the recalculated one.
 */
final readonly class SegmentEffortComparison
{
    public function __construct(
        private string $id,
        private string $name,
        private float $currentElapsedTimeInSeconds,
        private ?float $recalculatedElapsedTimeInSeconds,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCurrentElapsedTimeInSeconds(): float
    {
        return $this->currentElapsedTimeInSeconds;
    }

    public function getRecalculatedElapsedTimeInSeconds(): ?float
    {
        return $this->recalculatedElapsedTimeInSeconds;
    }

    /**
     * A recalculated time that is the same as the current one is not a change.
     */
    public function hasDifference(): bool
    {
        return null !== $this->recalculatedElapsedTimeInSeconds
            && abs($this->recalculatedElapsedTimeInSeconds - $this->currentElapsedTimeInSeconds) > 0.001;
    }
}
