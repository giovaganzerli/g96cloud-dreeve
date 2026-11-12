<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Collection\CollectionActivitySearch;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CollectionActivitySearchRequestHandler
{
    public function __construct(
        private CollectionActivitySearch $collectionActivitySearch,
    ) {
    }

    #[Route(path: '/api/internal/collections/activities', name: 'collection_activity_search', methods: ['GET'])]
    public function handle(): JsonResponse
    {
        return new JsonResponse(Json::encode(
            $this->collectionActivitySearch->search('')
        ), json: true);
    }
}
