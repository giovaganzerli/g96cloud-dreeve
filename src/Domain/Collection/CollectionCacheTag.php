<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Cache\Tag\ScopedCacheTag;

final readonly class CollectionCacheTag
{
    public static function for(CollectionId $collectionId): ScopedCacheTag
    {
        return ScopedCacheTag::for(RootCacheTag::COLLECTIONS, $collectionId->toUnprefixedString());
    }
}
