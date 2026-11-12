<?php

declare(strict_types=1);

namespace App\Domain\Collection\Statistics;

use App\Infrastructure\ValueObject\Collection;

/**
 * @extends Collection<CollectionStatistic>
 */
final class CollectionStatistics extends Collection
{
    public function getItemClassName(): string
    {
        return CollectionStatistic::class;
    }

    /**
     * @return array<string, CollectionStatistic[]>
     */
    public function groupedByCategory(): array
    {
        $grouped = [];
        foreach ($this->toArray() as $statistic) {
            $grouped[$statistic->getCategory()->value][] = $statistic;
        }

        return $grouped;
    }

    public function findByKey(string $key): ?CollectionStatistic
    {
        return $this->find(
            fn (CollectionStatistic $statistic): bool => $statistic->getKey() === $key
        );
    }
}
