<?php

declare(strict_types=1);

namespace App\Controller\Page\Collection;

use App\Application\Navigation\NavigationSection;
use App\Application\PageRenderer;
use App\Domain\Collection\Collection;
use App\Domain\Collection\CollectionActivities;
use App\Domain\Collection\CollectionCacheTag;
use App\Domain\Collection\CollectionId;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\Statistics\CollectionStatisticsCalculator;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\Context\AuthenticatedCacheContext;
use App\Infrastructure\Cache\Context\CacheContexts;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsController]
final readonly class CollectionRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionActivities $collectionActivities,
        private CollectionStatisticsCalculator $statisticsCalculator,
        private SettingsRepository $settingsRepository,
        private UrlGeneratorInterface $urlGenerator,
        private PageRenderer $pageRenderer,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/collections/{collectionId}', name: 'collection', requirements: ['collectionId' => 'collection-[^/]+'], methods: ['GET'])]
    public function handle(string $collectionId): HtmlResponse
    {
        try {
            $collection = $this->collectionRepository->find(
                CollectionId::fromPrefixedOrUnprefixed($collectionId)
            );
        } catch (EntityNotFound) {
            throw new NotFoundHttpException('Not found');
        }

        return $this->pageRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: sprintf('collections.%s', $collection->getId()->toUnprefixedString()),
                cacheTags: CacheTags::of(
                    CollectionCacheTag::for($collection->getId()),
                    RootCacheTag::COLLECTIONS,
                    RootCacheTag::ACTIVITIES,
                ),
                cacheContexts: CacheContexts::of(AuthenticatedCacheContext::class),
            ),
            render: fn (): string => $this->renderFor($collection),
            navigationSection: NavigationSection::COLLECTIONS,
        );
    }

    private function renderFor(Collection $collection): string
    {
        $activities = $this->collectionActivities->forCollection($collection);

        return $this->twig->load('html/collection/collection.html.twig')->render([
            'collection' => $collection,
            'activities' => $activities,
            'statistics' => $this->statisticsCalculator->calculate($collection, $activities),
            'unitSystem' => $this->settingsRepository->appearance()->getUnitSystem(),
            'polylinesUrl' => $this->urlGenerator->generate('collection_polylines', [
                'collectionId' => (string) $collection->getId(),
            ]),
        ]);
    }
}
