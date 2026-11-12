<?php

declare(strict_types=1);

namespace App\Domain\Collection\AddCollection;

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
use App\Infrastructure\Time\Clock\Clock;

final readonly class AddCollectionCommandHandler implements CommandHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionActivities $collectionActivities,
        private CollectionStatisticsCalculator $statisticsCalculator,
        private CollectionDescriptionGenerator $descriptionGenerator,
        private ImageStorage $imageStorage,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof AddCollection);

        $collection = Collection::create(
            collectionId: CollectionId::random(),
            createdOn: $this->clock->getCurrentDateTimeImmutable(),
            name: $command->getName(),
            description: $command->getDescription(),
            activityIds: $command->getActivityIds(),
            immichAlbumId: $command->getImmichAlbumId(),
            statisticOverrides: $command->getStatisticOverrides(),
            customStatistics: $command->getCustomStatistics(),
            statisticOrder: $command->getStatisticOrder(),
        );

        $newImage = $command->getNewImage();
        if ($newImage instanceof NewImage) {
            $collection = $collection->withLocalImagePath(
                $this->imageStorage->store(
                    newImage: $newImage,
                    directory: ImageDirectory::COLLECTIONS,
                )->toLocalImagePath()
            );
        }

        $collection = $this->withGeneratedDescription($collection);

        $this->collectionRepository->add($collection);
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
