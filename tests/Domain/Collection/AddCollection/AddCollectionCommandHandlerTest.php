<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\AddCollection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Collection\AddCollection\AddCollection;
use App\Domain\Collection\CollectionRepository;
use App\Infrastructure\CQRS\Command\Deserialize\CouldNotDeserializeCommand;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

class AddCollectionCommandHandlerTest extends ContainerTestCase
{
    public function testItAddsACollection(): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->build(),
            []
        ));

        $command = AddCollection::fromPayload([
            'name' => 'Summer in the Dolomites',
            'description' => 'A trip through the mountains',
            'activityIds' => ['1'],
            'immichAlbumId' => 'album-1',
            'statisticOverrides' => ['distance.total' => '250 km'],
            'customStatistics' => json_encode([
                ['id' => 'cost', 'label' => 'Travel cost', 'value' => '€ 120', 'unit' => '€', 'category' => 'other'],
            ], JSON_THROW_ON_ERROR),
            'statisticOrder' => json_encode(['distance.total', 'custom.cost'], JSON_THROW_ON_ERROR),
        ]);

        $this->getContainer()->get(\App\Infrastructure\CQRS\Command\Bus\CommandBus::class)->dispatch($command);

        /** @var CollectionRepository $collectionRepository */
        $collectionRepository = $this->getContainer()->get(CollectionRepository::class);
        $collections = $collectionRepository->findAll();

        $this->assertCount(1, $collections);
        $collection = $collections->getFirst();
        $this->assertNotNull($collection);
        $this->assertEquals('Summer in the Dolomites', $collection->getName());
        $this->assertEquals('A trip through the mountains', $collection->getDescription());
        $this->assertEquals('album-1', $collection->getImmichAlbumId());
        $this->assertCount(1, $collection->getActivityIds());
        $this->assertEquals(['distance.total' => '250 km'], $collection->getStatisticOverrides());
        $this->assertCount(1, $collection->getCustomStatistics());
        $this->assertEquals(['distance.total', 'custom.cost'], $collection->getStatisticOrder());
    }

    public function testItRequiresAName(): void
    {
        $this->expectException(CouldNotDeserializeCommand::class);

        AddCollection::fromPayload(['name' => '  ']);
    }

    public function testItRequiresAValidName(): void
    {
        $this->expectException(CouldNotDeserializeCommand::class);

        AddCollection::fromPayload([]);
    }
}
