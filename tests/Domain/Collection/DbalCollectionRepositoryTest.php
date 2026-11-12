<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Tests\ContainerTestCase;

class DbalCollectionRepositoryTest extends ContainerTestCase
{
    private CollectionRepository $collectionRepository;

    public function testFindAndAdd(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withName('Summer in the Dolomites')
            ->withDescription('A trip through the mountains')
            ->withLocalImagePath('files/collections/cover.jpg')
            ->withActivityId(ActivityId::fromUnprefixed('10'))
            ->withActivityId(ActivityId::fromUnprefixed('11'))
            ->withImmichAlbumId('album-1')
            ->withStatisticOverrides(['distance.total' => '250 km'])
            ->withCustomStatistic('cost', 'Travel cost', '€ 120', '€')
            ->withStatisticOrder(['distance.total', 'custom.cost'])
            ->build();

        $this->collectionRepository->add($collection);

        $found = $this->collectionRepository->find(CollectionId::fromUnprefixed('1'));

        $this->assertEquals('Summer in the Dolomites', $found->getName());
        $this->assertEquals('A trip through the mountains', $found->getDescription());
        $this->assertEquals('files/collections/cover.jpg', $found->getLocalImagePath());
        $this->assertEquals('/files/collections/cover.jpg', $found->getImageUrl());
        $this->assertEquals(
            ['activity-10', 'activity-11'],
            array_map(
                static fn (ActivityId $activityId): string => (string) $activityId,
                $found->getActivityIds()->toArray()
            )
        );
        $this->assertEquals('album-1', $found->getImmichAlbumId());
        $this->assertEquals(['distance.total' => '250 km'], $found->getStatisticOverrides());
        $this->assertEquals(['distance.total', 'custom.cost'], $found->getStatisticOrder());

        $customStatistics = $found->getCustomStatistics();
        $this->assertCount(1, $customStatistics);
        $this->assertEquals('Travel cost', $customStatistics->getFirst()?->getLabel());
        $this->assertEquals('€ 120', $customStatistics->getFirst()?->getValue());
        $this->assertEquals('€', $customStatistics->getFirst()?->getUnit());
    }

    public function testFindAll(): void
    {
        $this->collectionRepository->add(
            CollectionBuilder::fromDefaults()->withCollectionId(CollectionId::fromUnprefixed('1'))->build()
        );
        $this->collectionRepository->add(
            CollectionBuilder::fromDefaults()->withCollectionId(CollectionId::fromUnprefixed('2'))->build()
        );

        $collections = $this->collectionRepository->findAll();

        $this->assertCount(2, $collections);
        $this->assertEquals(2, $this->collectionRepository->count());
    }

    public function testUpdate(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withName('Original')
            ->build();
        $this->collectionRepository->add($collection);

        $collection = $collection
            ->withName('Updated')
            ->withDescription('A new description')
            ->withActivityIds(ActivityIds::fromArray([ActivityId::fromUnprefixed('5')]));

        $this->collectionRepository->update($collection);

        $found = $this->collectionRepository->find(CollectionId::fromUnprefixed('1'));
        $this->assertEquals('Updated', $found->getName());
        $this->assertEquals('A new description', $found->getDescription());
        $this->assertCount(1, $found->getActivityIds());
    }

    public function testDelete(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->build();
        $this->collectionRepository->add($collection);

        $collection->delete();
        $this->collectionRepository->delete($collection);

        $this->expectException(EntityNotFound::class);
        $this->collectionRepository->find(CollectionId::fromUnprefixed('1'));
    }

    public function testFindThrowsForAnUnknownCollection(): void
    {
        $this->expectException(EntityNotFound::class);
        $this->collectionRepository->find(CollectionId::fromUnprefixed('404'));
    }

    public function testItStoresCustomStatisticsAsJson(): void
    {
        $collection = CollectionBuilder::fromDefaults()
            ->withCollectionId(CollectionId::fromUnprefixed('1'))
            ->withCustomStatistic('nights', 'Nights in a tent', '4', 'nights')
            ->build();
        $this->collectionRepository->add($collection);

        $stored = $this->getConnection()->fetchOne(
            'SELECT customStatistics FROM Collection WHERE collectionId = :collectionId',
            ['collectionId' => 'collection-1']
        );

        $this->assertIsString($stored);
        $decoded = json_decode($stored, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('custom.nights', $decoded[0]['key']);
        $this->assertEquals('Nights in a tent', $decoded[0]['label']);
    }

    public function testFindByActivityId(): void
    {
        $this->collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('With the activity')
                ->withActivityId(ActivityId::fromUnprefixed('10'))
                ->build()
        );
        $this->collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('2'))
                ->withName('Without the activity')
                ->withActivityId(ActivityId::fromUnprefixed('11'))
                ->build()
        );

        $collections = $this->collectionRepository->findByActivityId(ActivityId::fromUnprefixed('10'));

        $this->assertCount(1, $collections);
        $this->assertEquals('With the activity', $collections->getFirst()?->getName());
    }

    public function testFindByActivityIdReturnsNothingForAnUnknownActivity(): void
    {
        $this->collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withActivityId(ActivityId::fromUnprefixed('10'))
                ->build()
        );

        $this->assertCount(
            0,
            $this->collectionRepository->findByActivityId(ActivityId::fromUnprefixed('404')),
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionRepository = $this->getContainer()->get(CollectionRepository::class);
    }
}
