<?php

declare(strict_types=1);

namespace App\Tests\Controller\Page\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Infrastructure\Serialization\Json;
use App\Tests\Controller\ControllerWebTestCase;
use App\Tests\Domain\Collection\CollectionBuilder;
use App\Tests\ProvideTestData;
use Spatie\Snapshots\MatchesSnapshots;

class CollectionsRequestHandlerTest extends ControllerWebTestCase
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

        $this->client->request('GET', '/collections');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringEndsWith(
            'collections',
            (string) $this->client->getResponse()->headers->get('X-Dreeve-Cache-Key'),
        );
        $this->assertMatchesHtmlSnapshot((string) $this->client->getResponse()->getContent());
    }

    public function testItShowsTheFilters(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/collections');

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-dataTable-settings', $content);
        $this->assertStringContainsString('data-dataTable-filter="sportType"', $content);
        $this->assertStringContainsString('data-dataTable-filter="start-date[]"', $content);
        $this->assertStringContainsString('data-dataTable-filter="countryCode"', $content);
        $this->assertStringContainsString('data-dataTable-filter="device"', $content);
        $this->assertStringContainsString('data-dataTable-filter="distance[]"', $content);
        $this->assertStringContainsString('data-dataTable-filter="elevation[]"', $content);
        $this->assertStringContainsString('data-dataTable-filter="hasDescription"', $content);
    }

    public function testItListsTheCollections(): void
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

        $this->client->request('GET', '/api/internal/collections/data-table');

        $this->assertResponseIsSuccessful();
        $rows = Json::decode((string) $this->client->getResponse()->getContent());
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Summer in the Dolomites', (string) $rows[0]['markup']);
        $this->assertStringContainsString('/collections/collection-1', (string) $rows[0]['markup']);
        $this->assertStringContainsString('summer in the dolomites', (string) $rows[0]['searchables']);
    }

    public function testTheDataTableRowsCanBeFiltered(): void
    {
        $this->provideFullTestSet();

        /** @var CollectionRepository $collectionRepository */
        $collectionRepository = $this->getContainer()->get(CollectionRepository::class);
        $collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('With description')
                ->withDescription('A trip through the mountains')
                ->build()
        );
        $collectionRepository->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('2'))
                ->withName('Without description')
                ->build()
        );

        $this->client->request('GET', '/api/internal/collections/data-table');

        $rows = Json::decode((string) $this->client->getResponse()->getContent());
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);

        $withDescription = array_values(array_filter(
            $rows,
            static fn (array $row): bool => 'with' === ($row['filterables']['hasDescription'] ?? null)
        ));
        $this->assertCount(1, $withDescription);
        $this->assertStringContainsString('With description', (string) $withDescription[0]['markup']);
    }
}
