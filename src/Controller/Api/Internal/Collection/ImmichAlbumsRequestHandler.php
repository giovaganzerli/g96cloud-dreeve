<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Integration\Immich\ImmichApi;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ImmichAlbumsRequestHandler
{
    public function __construct(
        private ImmichApi $immichApi,
    ) {
    }

    #[Route(path: '/admin/collections/immich-albums', name: 'admin_collection_immich_albums', methods: ['GET'], priority: 10)]
    public function handle(): JsonResponse
    {
        try {
            $albums = $this->immichApi->getAlbums();
        } catch (\Throwable) {
            return new JsonResponse(Json::encode([]), json: true);
        }

        $options = [];
        foreach ($albums as $album) {
            $options[] = [
                'value' => $album->getId(),
                'label' => $album->getName(),
            ];
        }

        return new JsonResponse(Json::encode($options), json: true);
    }
}
