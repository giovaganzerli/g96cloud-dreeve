<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\EnrichedActivity;
use App\Domain\Activity\EnrichedActivityRepository;

final readonly class CollectionActivities
{
    public function __construct(
        private EnrichedActivityRepository $enrichedActivityRepository,
    ) {
    }

    /**
     * @return EnrichedActivity[]
     */
    public function forCollection(Collection $collection): array
    {
        if ($collection->getActivityIds()->isEmpty()) {
            return [];
        }

        return $this->enrichedActivityRepository->findByIds($collection->getActivityIds());
    }
}
