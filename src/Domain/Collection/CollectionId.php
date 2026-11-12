<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class CollectionId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'collection-';
    }
}
