<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\ValueObject\Collection as BaseCollection;

/**
 * @extends BaseCollection<Collection>
 */
final class Collections extends BaseCollection
{
    public function getItemClassName(): string
    {
        return Collection::class;
    }
}
