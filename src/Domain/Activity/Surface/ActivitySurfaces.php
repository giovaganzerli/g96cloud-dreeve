<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\ValueObject\Collection as BaseCollection;

/**
 * @extends BaseCollection<ActivitySurface>
 */
final class ActivitySurfaces extends BaseCollection
{
    public function getItemClassName(): string
    {
        return ActivitySurface::class;
    }

    public function getForActivity(ActivityId $activityId): ?ActivitySurface
    {
        return $this->find(
            fn (ActivitySurface $activitySurface): bool => $activitySurface->getActivityId() == $activityId
        );
    }
}
