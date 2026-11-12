<?php

declare(strict_types=1);

namespace App\Domain\Collection\UpdateCollection;

use App\Domain\Collection\Collection;
use App\Domain\Collection\CollectionActivities;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\Description\CollectionDescriptionGenerator;
use App\Domain\Collection\Statistics\CollectionStatisticsCalculator;
use App\Domain\Image\ImageDirectory;
use App\Domain\Image\ImageStorage;
use App\Domain\Image\NewImage;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class UpdateCollectionCommandHandler implements CommandHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionActivities $collectionActivities,
        private CollectionStatisticsCalculator $statisticsCalculator,
        private CollectionDescriptionGenerator $descriptionGenerator,
        private ImageStorage $imageStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateCollection);

        $collection = $this->collectionRepository->find(
            CollectionId::fromPrefixedOrUnprefixed($command->getCollectionId())
        );

        $collection = $collection
            ->withName($command->getName())
            ->withDescription($command->getDescription())
            ->withActivityIds($command->getActivityIds())
            ->withImmichAlbumId($command->getImmichAlbumId())
            ->withStatisticOverrides($command->getStatisticOverrides())
            ->withCustomStatistics($command->getCustomStatistics())
            ->withStatisticOrder($command->getStatisticOrder());

        $newImage = $command->getNewImage();
        if ($newImage instanceof NewImage) {
            if (null !== ($existingImagePath = $collection->getLocalImagePath())) {
                $this->imageStorage->remove(
                    \App\Domain\Image\ImagePath::fromLocalImagePath($existingImagePath)
                );
            }
            $collection = $collection->withLocalImagePath(
                $this->imageStorage->store(
                    newImage: $newImage,
                    directory: ImageDirectory::COLLECTIONS,
                )->toLocalImagePath()
            );
        }

        foreach ($command->getRemovedImages() as $removedImage) {
            $this->imageStorage->remove($removedImage->getPath());
            if ($removedImage->getPath()->toLocalImagePath() === $collection->getLocalImagePath()) {
                $collection = $collection->withLocalImagePath(null);
            }
        }

        $collection = $this->withGeneratedDescription($collection);

        $this->collectionRepository->update($collection);
    }

    private function withGeneratedDescription(Collection $collection): Collection
    {
        if (null !== $collection->getDescription() && '' !== trim($collection->getDescription())) {
            return $collection;
        }

        $activities = $this->collectionActivities->forCollection($collection);
        if ([] === $activities) {
            return $collection;
        }

        $statistics = $this->statisticsCalculator->calculateAutomatic($activities);
        $description = $this->descriptionGenerator->generate($collection, $activities, $statistics);
        if (null === $description) {
            return $collection;
        }

        return $collection->withDescription($description);
    }
}
