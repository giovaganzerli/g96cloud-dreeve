<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Collection\CollectionBuilder;
use App\Tests\ProvideTestData;

class ManageCollectionOverviewRequestHandlerTest extends AdminWebTestCase
{
    use ProvideTestData;

    public function testItListsTheCollections(): void
    {
        static::getContainer()->get(CollectionRepository::class)->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->build()
        );

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Collection management', $crawler->filter('h3')->text());
        $this->assertStringContainsString('Summer in the Dolomites', $crawler->filter('table')->text());
    }

    public function testItShowsAnEmptyState(): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No collections added yet.', $crawler->filter('table')->text());
    }
}
