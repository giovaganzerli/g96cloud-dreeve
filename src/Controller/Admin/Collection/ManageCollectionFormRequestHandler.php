<?php

declare(strict_types=1);

namespace App\Controller\Admin\Collection;

use App\Domain\Collection\AddCollection\AddCollection;
use App\Domain\Collection\CollectionActivities;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\DeleteCollection\DeleteCollection;
use App\Domain\Collection\GenerateCollectionDescription\GenerateCollectionDescription;
use App\Domain\Collection\Statistics\CollectionStatisticsCalculator;
use App\Domain\Collection\UpdateCollection\UpdateCollection;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class ManageCollectionFormRequestHandler
{
    public function __construct(
        private Environment $twig,
        private CollectionRepository $collectionRepository,
        private CollectionActivities $collectionActivities,
        private CollectionStatisticsCalculator $statisticsCalculator,
    ) {
    }

    #[Route(path: '/admin/collections/add', name: 'admin_add_collection', methods: ['GET'], priority: 10)]
    public function handleAdd(): HtmlResponse
    {
        return new HtmlResponse($this->twig->render('html/admin/page/collection/edit-collection.html.twig', [
            'dispatchCommand' => AddCollection::getCommandName(),
            'collection' => null,
            'statistics' => null,
        ]));
    }

    #[Route(path: '/admin/collections/{collectionId}/edit', name: 'admin_edit_collection', methods: ['GET'], priority: 10)]
    public function handleEdit(string $collectionId): HtmlResponse
    {
        $collection = $this->collectionRepository->find(
            CollectionId::fromPrefixedOrUnprefixed($collectionId)
        );

        $activities = $this->collectionActivities->forCollection($collection);

        return new HtmlResponse($this->twig->render('html/admin/page/collection/edit-collection.html.twig', [
            'dispatchCommand' => UpdateCollection::getCommandName(),
            'collection' => $collection,
            'statistics' => $this->statisticsCalculator->calculate($collection, $activities),
            'generateDescriptionCommand' => GenerateCollectionDescription::getCommandName(),
        ]));
    }

    #[Route(path: '/admin/collections/{collectionId}/delete', name: 'admin_delete_collection', methods: ['GET'], priority: 10)]
    public function handleDelete(string $collectionId): HtmlResponse
    {
        try {
            $collection = $this->collectionRepository->find(
                CollectionId::fromPrefixedOrUnprefixed($collectionId)
            );
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Collection not found');
        }

        return new HtmlResponse($this->twig->render('html/admin/page/collection/delete-collection.html.twig', [
            'dispatchCommand' => DeleteCollection::getCommandName(),
            'collection' => $collection,
        ]));
    }
}
