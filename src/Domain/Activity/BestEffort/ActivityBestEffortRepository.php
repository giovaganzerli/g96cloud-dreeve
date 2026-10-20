<?php

declare(strict_types=1);

namespace App\Domain\Activity\BestEffort;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;

interface ActivityBestEffortRepository
{
    public function add(ActivityBestEffort $activityBestEffort): void;

    public function hasData(): bool;

    public function findActivityIdsThatNeedBestEffortsCalculation(): ActivityIds;

    public function findByActivity(ActivityId $activity): ActivityBestEfforts;

    /**
     * All the best efforts stored for the activity, including the ones where the
     * activity does not hold the overall record for that distance.
     */
    public function findAllByActivity(ActivityId $activityId): ActivityBestEfforts;

    public function deleteForActivity(ActivityId $activityId): void;
}
