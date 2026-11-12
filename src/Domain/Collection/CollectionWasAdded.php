<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\Eventing\DomainEvent;

final class CollectionWasAdded extends DomainEvent
{
}
