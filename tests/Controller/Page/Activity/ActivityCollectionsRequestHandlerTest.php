<?php

declare(strict_types=1);

namespace App\Tests\Controller\Page\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Tests\Controller\ControllerWebTestCase;
use App\Tests\Domain\Collection\CollectionBuilder;
use App\Tests\ProvideTestData;

class ActivityCollectionsRequestHandlerTest extends ControllerWebTestCase
{
    use ProvideTestData;

    public function testItShowsTheCollectionsTheActivityIsPartOf(): void
    {
        $this->provideFullTestSet();

        /** @var CollectionRepository $collectionRepository */
        $collectionRepository = $this->getContainer()->get(CollectionRepository::class);
        $collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomiti')
                ->withActivityId(ActivityId::fromUnprefixed('9756441741'))
                ->build()
        );

        $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Summer in the Dolomiti', $content);
        $this->assertStringContainsString('/collections/collection-1', $content);
    }

    public function testItDoesNotShowACollectionSectionWithoutCollections(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            '/collections/collection-',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
