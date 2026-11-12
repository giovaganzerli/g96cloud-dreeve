<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Collection\CollectionBuilder;
use App\Tests\ProvideTestData;

class ManageCollectionFormRequestHandlerTest extends AdminWebTestCase
{
    use ProvideTestData;

    public function testRendersTheAddForm(): void
    {
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections/add');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Add collection', $crawler->filter('h3')->text());

        $form = $crawler->filter('form[data-dispatch-command="add-collection"]');
        $this->assertCount(1, $form);

        $this->assertCount(0, $form->filter('input[name="collectionId"]'));
        $this->assertSame('', $form->filter('input[name="name"]')->attr('value'));
        $this->assertSame('', $form->filter('textarea[name="description"]')->text());
    }

    public function testRendersTheEditFormPrefilledWithTheCollection(): void
    {
        static::getContainer()->get(CollectionRepository::class)->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->withDescription('A trip through the mountains')
                ->build()
        );

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections/collection-1/edit');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Edit collection', $crawler->filter('h3')->text());

        $form = $crawler->filter('form[data-dispatch-command="update-collection"]');
        $this->assertCount(1, $form);

        $this->assertSame('collection-1', $form->filter('input[name="collectionId"]')->attr('value'));
        $this->assertSame('Summer in the Dolomites', $form->filter('input[name="name"]')->attr('value'));
        $this->assertSame('A trip through the mountains', $form->filter('textarea[name="description"]')->text());
    }

    public function testItShowsTheCalculatedStatistics(): void
    {
        static::getContainer()->get(CollectionRepository::class)->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->build()
        );

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections/collection-1/edit');

        $form = $crawler->filter('form[data-dispatch-command="update-collection"]');
        $this->assertCount(1, $form->filter('input[name="statisticOverrides[distance.total]"]'));
        $this->assertCount(1, $form->filter('input[name="statisticOverrides[speed.average]"]'));
    }

    public function testRendersTheDeleteForm(): void
    {
        static::getContainer()->get(CollectionRepository::class)->add(
            CollectionBuilder::fromDefaults()
                ->withCollectionId(CollectionId::fromUnprefixed('1'))
                ->withName('Summer in the Dolomites')
                ->build()
        );

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/collections/collection-1/delete');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Delete collection', $crawler->filter('h3')->text());

        $form = $crawler->filter('form[data-dispatch-command="delete-collection"]');
        $this->assertCount(1, $form);
        $this->assertSame('collection-1', $form->filter('input[name="collectionId"]')->attr('value'));
    }
}
