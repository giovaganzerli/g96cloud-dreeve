<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Collection;

use App\Domain\Collection\CollectionRepository;
use App\Infrastructure\Serialization\Json;
use App\Tests\Controller\Admin\AdminWebTestCase;
use Symfony\Component\HttpFoundation\Response;

class DispatchCollectionCommandRequestHandlerTest extends AdminWebTestCase
{
    public function testItAddsACollection(): void
    {
        $this->client->loginUser($this->adminUser());

        $this->client->request(
            method: 'POST',
            uri: '/admin/dispatch-command',
            server: ['HTTP_X_CSRF_TOKEN' => $this->validCsrfToken()],
            content: Json::encode([
                'commandName' => 'add-collection',
                'payload' => [
                    'name' => 'Summer in the Dolomites',
                    'description' => 'A trip through the mountains',
                    'activityIds' => [],
                    'statisticOverrides' => [],
                    'customStatistics' => '[]',
                    'statisticOrder' => '[]',
                ],
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $collections = static::getContainer()->get(CollectionRepository::class)->findAll();
        $this->assertCount(1, $collections);
        $this->assertEquals('Summer in the Dolomites', $collections->getFirst()?->getName());
    }

    public function testItUpdatesACollection(): void
    {
        $this->client->loginUser($this->adminUser());

        $this->client->request(
            method: 'POST',
            uri: '/admin/dispatch-command',
            server: ['HTTP_X_CSRF_TOKEN' => $this->validCsrfToken()],
            content: Json::encode([
                'commandName' => 'add-collection',
                'payload' => [
                    'name' => 'Summer in the Dolomites',
                    'statisticOverrides' => [],
                    'customStatistics' => '[]',
                    'statisticOrder' => '[]',
                ],
            ]),
        );

        $collection = static::getContainer()->get(CollectionRepository::class)->findAll()->getFirst();
        $this->assertNotNull($collection);

        $this->client->request(
            method: 'POST',
            uri: '/admin/dispatch-command',
            server: ['HTTP_X_CSRF_TOKEN' => $this->validCsrfToken()],
            content: Json::encode([
                'commandName' => 'update-collection',
                'payload' => [
                    'collectionId' => (string) $collection->getId(),
                    'name' => 'Winter in the Dolomites',
                    'statisticOverrides' => [],
                    'customStatistics' => '[]',
                    'statisticOrder' => '[]',
                ],
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $updated = static::getContainer()->get(CollectionRepository::class)->findAll()->getFirst();
        $this->assertEquals('Winter in the Dolomites', $updated?->getName());
    }

    public function testItRejectsAnInvalidPayload(): void
    {
        $this->client->loginUser($this->adminUser());

        $this->client->request(
            method: 'POST',
            uri: '/admin/dispatch-command',
            server: ['HTTP_X_CSRF_TOKEN' => $this->validCsrfToken()],
            content: Json::encode([
                'commandName' => 'add-collection',
                'payload' => [
                    'name' => '',
                ],
            ]),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    private function validCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/collections');

        return $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }
}
