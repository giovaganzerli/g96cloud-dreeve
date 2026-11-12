<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\ActivityId;

interface CollectionRepository
{
    public function add(Collection $collection): void;

    public function update(Collection $collection): void;

    public function delete(Collection $collection): void;

    public function find(CollectionId $collectionId): Collection;

    public function findAll(): Collections;

    /**
     * The collections the activity is part of.
     */
    public function findByActivityId(ActivityId $activityId): Collections;

    public function count(): int;
}
