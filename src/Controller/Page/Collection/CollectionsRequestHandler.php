<?php

declare(strict_types=1);

namespace App\Controller\Page\Collection;

use App\Application\Countries;
use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Activity\RealWorldMap;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Gear\RecordingDevice\RecordingDeviceRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsController]
final readonly class CollectionsRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private SportTypeRepository $sportTypeRepository,
        private RecordingDeviceRepository $recordingDeviceRepository,
        private Countries $countries,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private PageRenderer $pageRenderer,
    ) {
    }

    #[Route(path: '/collections', name: 'collections', methods: ['GET'])]
    public function handle(): HtmlResponse
    {
        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'collections',
                cacheTags: CacheTags::of(
                    RootCacheTag::COLLECTIONS,
                    RootCacheTag::ACTIVITIES,
                ),
            ),
            render: fn (): string => $this->renderFor(),
            navigationSection: NavigationSection::COLLECTIONS,
        );
    }

    private function renderFor(): string
    {
        $map = new RealWorldMap();

        return $this->twig->load('html/collection/collections.html.twig')->render([
            'totalCollectionCount' => $this->collectionRepository->count(),
            'sportTypes' => $this->sportTypeRepository->findAll(),
            'devices' => $this->recordingDeviceRepository->findAll(),
            'countries' => $this->countries->getUsedInActivities(),
            'leaflet' => [
                'polylineUrl' => $this->urlGenerator->generate('collections_polylines'),
                'map' => $map,
            ],
        ]);
    }
}
