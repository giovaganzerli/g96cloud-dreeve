<?php

declare(strict_types=1);

namespace App\Domain\Collection\GenerateCollectionDescription;

use App\Domain\Collection\CollectionActivities;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\Description\CollectionDescriptionGenerator;
use App\Domain\Collection\Statistics\CollectionStatisticsCalculator;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;

final readonly class GenerateCollectionDescriptionCommandHandler implements CommandHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionActivities $collectionActivities,
        private CollectionStatisticsCalculator $statisticsCalculator,
        private CollectionDescriptionGenerator $descriptionGenerator,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof GenerateCollectionDescription);

        $collection = $this->collectionRepository->find(
            CollectionId::fromPrefixedOrUnprefixed($command->getCollectionId())
        );

        $activities = $this->collectionActivities->forCollection($collection);
        if ([] === $activities) {
            throw CouldNotProcessCommand::withReason('Add activities to the collection before generating a description.');
        }

        $statistics = $this->statisticsCalculator->calculate($collection, $activities);
        $description = $this->descriptionGenerator->generate($collection, $activities, $statistics);
        if (null === $description) {
            throw CouldNotProcessCommand::withReason('The description could not be generated. Is the AI integration enabled?');
        }

        $this->collectionRepository->update(
            $collection->withDescription($description)
        );
    }
}
