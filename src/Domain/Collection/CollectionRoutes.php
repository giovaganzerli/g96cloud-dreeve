<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

final readonly class CollectionRoutes
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
    ) {
    }

    /**
     * The coordinates of every activity in the collection.
     *
     * @return list<list<array{float, float}>>
     */
    public function forCollection(Collection $collection): array
    {
        $routes = [];
        foreach ($collection->getActivityIds() as $activityId) {
            try {
                $activity = $this->activityRepository->find($activityId);
            } catch (EntityNotFound) {
                continue;
            }

            if ($coordinates = $this->routeCoordinates($activity)) {
                $routes[] = $coordinates;
            }
        }

        return $routes;
    }

    /**
     * @return list<array{float, float}>
     */
    private function routeCoordinates(Activity $activity): array
    {
        try {
            $latLng = $this->activityStreamRepository->findOneByActivityAndStreamType(
                activityId: $activity->getId(),
                streamType: StreamType::LAT_LNG,
            )->getData();

            if ([] !== $latLng) {
                /** @var list<array{float, float}> $coordinates */
                $coordinates = [];
                foreach ($latLng as $pair) {
                    $coordinates[] = [(float) $pair[0], (float) $pair[1]];
                }

                return $coordinates;
            }
        } catch (EntityNotFound) {
        }

        $polyline = $activity->getEncodedPolyline();
        if (!$polyline instanceof EncodedPolyline) {
            return [];
        }

        /** @var list<array{float, float}> $coordinates */
        $coordinates = [];
        foreach ($polyline->decodeAndPairLatLng() as $pair) {
            $coordinates[] = [(float) $pair[0], (float) $pair[1]];
        }

        return $coordinates;
    }
}
