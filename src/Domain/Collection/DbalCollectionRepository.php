<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Collection\Statistics\CollectionStatistic;
use App\Domain\Collection\Statistics\CollectionStatisticCategory;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Infrastructure\Eventing\EventBus;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;

final readonly class DbalCollectionRepository extends DbalRepository implements CollectionRepository
{
    public function __construct(
        Connection $connection,
        private EventBus $eventBus,
    ) {
        parent::__construct($connection);
    }

    public function add(Collection $collection): void
    {
        $sql = 'INSERT INTO Collection (collectionId, createdOn, name, description, localImagePath, activityIds, immichAlbumId, statisticOverrides, customStatistics, statisticOrder)
        VALUES (:collectionId, :createdOn, :name, :description, :localImagePath, :activityIds, :immichAlbumId, :statisticOverrides, :customStatistics, :statisticOrder)';

        $this->connection->executeStatement($sql, $this->parameters($collection));

        $this->eventBus->publishEvents($collection->getRecordedEvents());
    }

    public function update(Collection $collection): void
    {
        $sql = 'UPDATE Collection SET
                    name = :name,
                    description = :description,
                    localImagePath = :localImagePath,
                    activityIds = :activityIds,
                    immichAlbumId = :immichAlbumId,
                    statisticOverrides = :statisticOverrides,
                    customStatistics = :customStatistics,
                    statisticOrder = :statisticOrder
                WHERE collectionId = :collectionId';

        $this->connection->executeStatement($sql, $this->parameters($collection));

        $this->eventBus->publishEvents($collection->getRecordedEvents());
    }

    public function delete(Collection $collection): void
    {
        $this->connection->executeStatement(
            'DELETE FROM Collection WHERE collectionId = :collectionId',
            ['collectionId' => $collection->getId()]
        );

        $this->eventBus->publishEvents($collection->getRecordedEvents());
    }

    public function find(CollectionId $collectionId): Collection
    {
        $result = $this->connection->executeQuery(
            'SELECT * FROM Collection WHERE collectionId = :collectionId',
            ['collectionId' => $collectionId]
        )->fetchAssociative();

        if (!$result) {
            throw new EntityNotFound(sprintf('Collection "%s" not found', $collectionId));
        }

        return $this->hydrate($result);
    }

    public function findAll(): Collections
    {
        $results = $this->connection->executeQuery(
            'SELECT * FROM Collection ORDER BY createdOn DESC'
        )->fetchAllAssociative();

        return Collections::fromArray(array_map(
            $this->hydrate(...),
            $results
        ));
    }

    public function count(): int
    {
        return (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM Collection'
        )->fetchOne();
    }

    public function findByActivityId(ActivityId $activityId): Collections
    {
        $results = $this->connection->executeQuery(
            'SELECT c.* FROM Collection c
            INNER JOIN json_each(c.activityIds) AS activity ON activity.value = :activityId
            ORDER BY c.createdOn DESC',
            ['activityId' => (string) $activityId]
        )->fetchAllAssociative();

        return Collections::fromArray(array_map(
            $this->hydrate(...),
            $results
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(Collection $collection): array
    {
        return [
            'collectionId' => $collection->getId(),
            'createdOn' => $collection->getCreatedOn(),
            'name' => $collection->getName(),
            'description' => $collection->getDescription(),
            'localImagePath' => $collection->getLocalImagePath(),
            'activityIds' => Json::encode($collection->getActivityIds()),
            'immichAlbumId' => $collection->getImmichAlbumId(),
            'statisticOverrides' => Json::encode($collection->getStatisticOverrides()),
            'customStatistics' => Json::encode(array_map(
                static fn (CollectionStatistic $statistic): array => $statistic->jsonSerialize(),
                $collection->getCustomStatistics()->toArray()
            )),
            'statisticOrder' => Json::encode($collection->getStatisticOrder()),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): Collection
    {
        return Collection::fromState(
            collectionId: CollectionId::fromString((string) $result['collectionId']),
            createdOn: SerializableDateTime::fromString((string) $result['createdOn']),
            name: (string) $result['name'],
            description: null !== $result['description'] ? (string) $result['description'] : null,
            localImagePath: null !== $result['localImagePath'] ? (string) $result['localImagePath'] : null,
            activityIds: $this->hydrateActivityIds((string) ($result['activityIds'] ?? '')),
            immichAlbumId: null !== $result['immichAlbumId'] ? (string) $result['immichAlbumId'] : null,
            statisticOverrides: $this->hydrateStatisticOverrides((string) ($result['statisticOverrides'] ?? '')),
            customStatistics: $this->hydrateCustomStatistics((string) ($result['customStatistics'] ?? '')),
            statisticOrder: $this->hydrateStatisticOrder((string) ($result['statisticOrder'] ?? '')),
        );
    }

    private function hydrateActivityIds(string $activityIds): ActivityIds
    {
        $decoded = Json::decode($activityIds);
        if (!is_array($decoded)) {
            return ActivityIds::empty();
        }

        $ids = [];
        foreach ($decoded as $activityId) {
            if (is_string($activityId) && '' !== trim($activityId)) {
                $ids[] = ActivityId::fromPrefixedOrUnprefixed(trim($activityId));
            }
        }

        return ActivityIds::fromArray($ids);
    }

    /**
     * @return array<string, string>
     */
    private function hydrateStatisticOverrides(string $statisticOverrides): array
    {
        if ('' === trim($statisticOverrides)) {
            return [];
        }

        $decoded = Json::decode($statisticOverrides);
        if (!is_array($decoded)) {
            return [];
        }

        $overrides = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $overrides[$key] = (string) $value;
            }
        }

        return $overrides;
    }

    private function hydrateCustomStatistics(string $customStatistics): CollectionStatistics
    {
        if ('' === trim($customStatistics)) {
            return CollectionStatistics::empty();
        }

        $decoded = Json::decode($customStatistics);
        if (!is_array($decoded)) {
            return CollectionStatistics::empty();
        }

        $statistics = [];
        foreach ($decoded as $statistic) {
            if (!is_array($statistic) || !isset($statistic['key']) || !is_string($statistic['key'])) {
                continue;
            }
            $category = CollectionStatisticCategory::tryFrom((string) ($statistic['category'] ?? ''));
            $statistics[] = CollectionStatistic::create(
                key: $statistic['key'],
                label: (string) ($statistic['label'] ?? ''),
                value: (string) ($statistic['value'] ?? ''),
                unit: isset($statistic['unit']) && is_string($statistic['unit']) ? $statistic['unit'] : null,
                icon: isset($statistic['icon']) && is_string($statistic['icon']) ? $statistic['icon'] : null,
                category: $category,
                isManuallyEdited: true,
                numericValue: isset($statistic['numericValue']) && is_numeric($statistic['numericValue'])
                    ? (float) $statistic['numericValue']
                    : null,
            );
        }

        return CollectionStatistics::fromArray($statistics);
    }

    /**
     * @return string[]
     */
    private function hydrateStatisticOrder(string $statisticOrder): array
    {
        if ('' === trim($statisticOrder)) {
            return [];
        }

        $decoded = Json::decode($statisticOrder);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}
