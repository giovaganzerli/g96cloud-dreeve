<?php

declare(strict_types=1);

namespace App\Domain\Collection\DeleteCollection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Image\ImagePath;
use App\Domain\Image\ImageStorage;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class DeleteCollectionCommandHandler implements CommandHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private ImageStorage $imageStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof DeleteCollection);

        $collection = $this->collectionRepository->find(
            CollectionId::fromPrefixedOrUnprefixed($command->getCollectionId())
        );

        if (null !== ($localImagePath = $collection->getLocalImagePath())) {
            $this->imageStorage->remove(ImagePath::fromLocalImagePath($localImagePath));
        }

        $collection->delete();

        $this->collectionRepository->delete($collection);
    }
}
