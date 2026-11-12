<?php

declare(strict_types=1);

namespace App\Tests\Controller\Page\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Tests\Controller\ControllerWebTestCase;
use App\Tests\Domain\Collection\CollectionBuilder;
use App\Tests\ProvideTestData;
use Spatie\Snapshots\MatchesSnapshots;

class CollectionRequestHandlerTest extends ControllerWebTestCase
{
    use MatchesSnapshots;
    use ProvideTestData;

    public function testRender(): void
    {
        $this->provideFullTestSet();

        /** @var CollectionRepository $collectionRepository */
        $collectionRepository = $this->getContainer()->get(CollectionRepository::class);
        $collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->withDescription('A trip through the mountains')
                ->build()
        );

        $this->client->request('GET', '/collections/collection-1');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString(
            'collections.1',
            (string) $this->client->getResponse()->headers->get('X-Dreeve-Cache-Key'),
        );
        $this->assertMatchesHtmlSnapshot((string) $this->client->getResponse()->getContent());
    }

    public function testItReturnsNotFoundForAnUnknownCollection(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/collections/collection-404');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testItShowsTheStatistics(): void
    {
        $this->provideFullTestSet();

        /** @var CollectionRepository $collectionRepository */
        $collectionRepository = $this->getContainer()->get(CollectionRepository::class);
        $collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->build()
        );

        $this->client->request('GET', '/collections/collection-1');

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Total distance', $content);
        $this->assertStringContainsString('Countries visited', $content);
        $this->assertStringContainsString('Distance on asphalt', $content);
    }
}
