<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\ArrayParameterType;

final readonly class DbalActivitySurfaceRepository extends DbalRepository implements ActivitySurfaceRepository
{
    public function add(ActivitySurface $activitySurface): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ActivitySurface (activityId, pavedDistanceInMeter, unpavedDistanceInMeter, unknownDistanceInMeter, analyzedOn)
            VALUES (:activityId, :pavedDistanceInMeter, :unpavedDistanceInMeter, :unknownDistanceInMeter, :analyzedOn)',
            [
                'activityId' => $activitySurface->getActivityId(),
                'pavedDistanceInMeter' => $activitySurface->getPavedDistanceInMeter(),
                'unpavedDistanceInMeter' => $activitySurface->getUnpavedDistanceInMeter(),
                'unknownDistanceInMeter' => $activitySurface->getUnknownDistanceInMeter(),
                'analyzedOn' => $activitySurface->getAnalyzedOn(),
            ]
        );
    }

    public function deleteForActivity(ActivityId $activityId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ActivitySurface WHERE activityId = :activityId',
            ['activityId' => $activityId]
        );
    }

    public function findByActivityId(ActivityId $activityId): ?ActivitySurface
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM ActivitySurface WHERE activityId = :activityId',
            ['activityId' => $activityId]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->hydrate($result);
    }

    public function findByActivityIds(ActivityIds $activityIds): ActivitySurfaces
    {
        if ($activityIds->isEmpty()) {
            return ActivitySurfaces::empty();
        }

        $results = $this->connection->executeQuery(
            'SELECT * FROM ActivitySurface WHERE activityId IN (:activityIds)',
            ['activityIds' => array_map(strval(...), $activityIds->toArray())],
            ['activityIds' => ArrayParameterType::STRING]
        )->fetchAllAssociative();

        return ActivitySurfaces::fromArray(array_map(
            $this->hydrate(...),
            $results
        ));
    }

    public function findActivityIdsThatNeedSurfaceAnalysis(): ActivityIds
    {
        $results = $this->connection->executeQuery(
            'SELECT a.activityId FROM Activity a
            LEFT JOIN ActivitySurface s ON s.activityId = a.activityId
            WHERE s.activityId IS NULL AND a.polyline IS NOT NULL AND a.polyline != ""
            ORDER BY a.startDateTime ASC'
        )->fetchFirstColumn();

        return ActivityIds::fromArray(array_map(
            ActivityId::fromString(...),
            $results
        ));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): ActivitySurface
    {
        return new ActivitySurface(
            activityId: ActivityId::fromString((string) $result['activityId']),
            pavedDistanceInMeter: (float) $result['pavedDistanceInMeter'],
            unpavedDistanceInMeter: (float) $result['unpavedDistanceInMeter'],
            unknownDistanceInMeter: (float) $result['unknownDistanceInMeter'],
            analyzedOn: SerializableDateTime::fromString((string) $result['analyzedOn']),
        );
    }
}
