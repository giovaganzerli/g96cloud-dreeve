<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\Eventing\DomainEvent;

final class CollectionWasDeleted extends DomainEvent
{
    public function __construct(
        private readonly CollectionId $collectionId,
    ) {
    }

    public function getCollectionId(): CollectionId
    {
        return $this->collectionId;
    }
}
