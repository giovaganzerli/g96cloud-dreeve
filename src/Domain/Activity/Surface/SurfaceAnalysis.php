<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

final readonly class SurfaceAnalysis
{
    public function __construct(
        private float $pavedDistanceInMeter,
        private float $unpavedDistanceInMeter,
        private float $unknownDistanceInMeter,
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public function getPavedDistanceInMeter(): float
    {
        return $this->pavedDistanceInMeter;
    }

    public function getUnpavedDistanceInMeter(): float
    {
        return $this->unpavedDistanceInMeter;
    }

    public function getUnknownDistanceInMeter(): float
    {
        return $this->unknownDistanceInMeter;
    }

    public function getTotalDistanceInMeter(): float
    {
        return $this->pavedDistanceInMeter + $this->unpavedDistanceInMeter + $this->unknownDistanceInMeter;
    }

    public function isEmpty(): bool
    {
        return 0.0 === $this->getTotalDistanceInMeter();
    }

    public function add(self $other): self
    {
        return new self(
            $this->pavedDistanceInMeter + $other->pavedDistanceInMeter,
            $this->unpavedDistanceInMeter + $other->unpavedDistanceInMeter,
            $this->unknownDistanceInMeter + $other->unknownDistanceInMeter,
        );
    }
}
