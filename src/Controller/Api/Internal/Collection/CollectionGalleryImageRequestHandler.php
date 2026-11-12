<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Integration\Immich\ImmichApi;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Security\Visitor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CollectionGalleryImageRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private ImmichApi $immichApi,
        private Visitor $visitor,
    ) {
    }

    #[Route(path: '/api/internal/collections/{collectionId}/gallery/{assetId}', name: 'collection_gallery_image', requirements: ['collectionId' => 'collection-[^/]+', 'assetId' => '[^/]+'], methods: ['GET'])]
    public function handle(string $collectionId, string $assetId): Response
    {
        try {
            $collection = $this->collectionRepository->find(
                CollectionId::fromPrefixedOrUnprefixed($collectionId)
            );
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Not found');
        }

        if (null === $collection->getImmichAlbumId()) {
            throw new NotFoundHttpException('Not found');
        }

        if (!$this->visitor->isTrusted()) {
            // Demo mode: don't expose the photos of the athlete.
            return new RedirectResponse(
                sprintf('https://picsum.photos/seed/%s/800/600', urlencode($assetId)),
                Response::HTTP_FOUND,
            );
        }

        $image = $this->immichApi->getAssetPreview($assetId);
        if (!$image instanceof \App\Domain\Integration\Immich\ImmichImage) {
            throw new NotFoundHttpException('Not found');
        }

        return new Response($image->getContent(), Response::HTTP_OK, [
            'Content-Type' => $image->getContentType(),
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
