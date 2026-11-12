<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class CollectionInvalidateCacheTagsListener
{
    public function __construct(
        private RenderCache $renderCache,
    ) {
    }

    #[AsEventListener]
    public function reactToCollectionWasAdded(CollectionWasAdded $event): void
    {
        $this->renderCache->invalidateTags(RootCacheTag::COLLECTIONS);
    }

    #[AsEventListener]
    public function reactToCollectionWasUpdated(CollectionWasUpdated $event): void
    {
        $this->renderCache->invalidateTags(
            RootCacheTag::COLLECTIONS,
            CollectionCacheTag::for($event->getCollectionId()),
        );
    }

    #[AsEventListener]
    public function reactToCollectionWasDeleted(CollectionWasDeleted $event): void
    {
        $this->renderCache->invalidateTags(
            RootCacheTag::COLLECTIONS,
            CollectionCacheTag::for($event->getCollectionId()),
        );
    }
}
