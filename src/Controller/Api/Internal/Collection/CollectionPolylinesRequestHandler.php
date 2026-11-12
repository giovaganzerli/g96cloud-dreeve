<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Collection\CollectionCacheTag;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\CollectionRoutes;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\CacheableRenderer;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CollectionPolylinesRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionRoutes $collectionRoutes,
        private CacheableRenderer $cacheableRenderer,
    ) {
    }

    #[Route(path: '/api/internal/collections/{collectionId}/polylines', name: 'collection_polylines', requirements: ['collectionId' => 'collection-[^/]+'], methods: ['GET'])]
    public function handle(string $collectionId): JsonResponse
    {
        try {
            $collection = $this->collectionRepository->find(
                CollectionId::fromPrefixedOrUnprefixed($collectionId)
            );
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Not found');
        }

        $render = $this->cacheableRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: sprintf('collections.%s.polylines', $collection->getId()->toUnprefixedString()),
                cacheTags: CacheTags::of(CollectionCacheTag::for($collection->getId())),
            ),
            render: fn (): string => Json::encode($this->collectionRoutes->forCollection($collection)),
        );

        return new JsonResponse($render->getContent() ?? '[]', headers: $render->getCacheHeaders(), json: true);
    }
}
