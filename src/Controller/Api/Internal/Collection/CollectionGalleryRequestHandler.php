<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\Gallery\CollectionGallery;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Security\Visitor;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsController]
final readonly class CollectionGalleryRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionGallery $collectionGallery,
        private Visitor $visitor,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(path: '/api/internal/collections/{collectionId}/gallery', name: 'collection_gallery', requirements: ['collectionId' => 'collection-[^/]+'], methods: ['GET'])]
    public function handle(string $collectionId): JsonResponse
    {
        try {
            $collection = $this->collectionRepository->find(
                CollectionId::fromPrefixedOrUnprefixed($collectionId)
            );
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Not found');
        }

        $photos = [];
        foreach ($this->collectionGallery->randomPhotos($collection) as $asset) {
            $photos[] = [
                'id' => $asset->getId(),
                'url' => $this->urlGenerator->generate('collection_gallery_image', [
                    'collectionId' => (string) $collection->getId(),
                    'assetId' => $asset->getId(),
                ]),
            ];
        }

        return new JsonResponse(Json::encode([
            'photos' => $photos,
            'albumUrl' => $this->visitor->isTrusted() ? $this->collectionGallery->albumUrl($collection) : null,
        ]), json: true);
    }
}
