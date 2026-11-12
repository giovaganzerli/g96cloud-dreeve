<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ActivitySurface')]
final readonly class ActivitySurface
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'string')]
        private ActivityId $activityId,
        #[ORM\Column(type: 'float')]
        private float $pavedDistanceInMeter,
        #[ORM\Column(type: 'float')]
        private float $unpavedDistanceInMeter,
        #[ORM\Column(type: 'float')]
        private float $unknownDistanceInMeter,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $analyzedOn,
    ) {
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
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

    public function getAnalyzedOn(): SerializableDateTime
    {
        return $this->analyzedOn;
    }

    public function getAnalysis(): SurfaceAnalysis
    {
        return new SurfaceAnalysis(
            $this->pavedDistanceInMeter,
            $this->unpavedDistanceInMeter,
            $this->unknownDistanceInMeter,
        );
    }
}
