<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\CollectionRoutes;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\CacheableRenderer;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CollectionsPolylinesRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionRoutes $collectionRoutes,
        private CacheableRenderer $cacheableRenderer,
    ) {
    }

    #[Route(path: '/api/internal/collections/polylines', name: 'collections_polylines', methods: ['GET'])]
    public function handle(): JsonResponse
    {
        $render = $this->cacheableRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'collections.polylines',
                cacheTags: CacheTags::of(
                    RootCacheTag::COLLECTIONS,
                    RootCacheTag::ACTIVITIES,
                ),
            ),
            render: function (): string {
                $polylines = [];
                foreach ($this->collectionRepository->findAll() as $collection) {
                    foreach ($this->collectionRoutes->forCollection($collection) as $route) {
                        $polylines[] = $route;
                    }
                }

                return Json::encode($polylines);
            },
        );

        return new JsonResponse($render->getContent() ?? '[]', headers: $render->getCacheHeaders(), json: true);
    }
}
