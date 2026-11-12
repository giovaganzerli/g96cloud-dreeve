<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\Repository\DbalRepository;

final readonly class CollectionActivitySearch extends DbalRepository
{
    private const int DEFAULT_LIMIT = 50;

    /**
     * Searches the completed activities, so they can be added to a collection.
     *
     * @return list<array{value: string, label: string}>
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        $tokens = array_values(array_filter(preg_split('/\s+/', $query) ?: []));

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('a.activityId', 'a.name', 'a.sportType', 'a.startDateTime')
            ->from('Activity', 'a')
            ->orderBy('a.startDateTime', 'DESC')
            ->setMaxResults($limit);

        foreach ($tokens as $index => $token) {
            $parameter = 'token'.$index;
            $queryBuilder->andWhere(sprintf(
                '(a.name LIKE :%1$s OR a.activityId LIKE :%1$s OR a.startDateTime LIKE :%1$s OR a.sportType LIKE :%1$s)',
                $parameter
            ));
            $queryBuilder->setParameter($parameter, '%'.$token.'%');
        }

        $results = $queryBuilder->executeQuery()->fetchAllAssociative();

        $options = [];
        foreach ($results as $result) {
            $activityId = ActivityId::fromString((string) $result['activityId']);
            $options[] = [
                'value' => $activityId->toUnprefixedString(),
                'label' => sprintf(
                    '%s — %s (%s)',
                    (string) $result['name'],
                    (string) $result['startDateTime'],
                    (string) $result['sportType'],
                ),
            ];
        }

        return $options;
    }
}
