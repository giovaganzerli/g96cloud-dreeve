<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;

interface ActivitySurfaceRepository
{
    public function add(ActivitySurface $activitySurface): void;

    public function deleteForActivity(ActivityId $activityId): void;

    public function findByActivityId(ActivityId $activityId): ?ActivitySurface;

    public function findByActivityIds(ActivityIds $activityIds): ActivitySurfaces;

    /**
     * Returns the ids of the activities that have a route, but no surface analysis yet.
     */
    public function findActivityIdsThatNeedSurfaceAnalysis(): ActivityIds;
}
