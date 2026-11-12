<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal\Collection;

use App\Application\Countries;
use App\Domain\Collection\CollectionRepository;
use App\Domain\Collection\CollectionSummaryBuilder;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\CacheableRenderer;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\DataTableRow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class CollectionDataTableRequestHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private CollectionSummaryBuilder $collectionSummaryBuilder,
        private Countries $countries,
        private SettingsRepository $settingsRepository,
        private Environment $twig,
        private CacheableRenderer $cacheableRenderer,
    ) {
    }

    #[Route(path: '/api/internal/collections/data-table', name: 'collection_data_table', methods: ['GET'])]
    public function handle(): JsonResponse
    {
        $render = $this->cacheableRenderer->render(
            cacheability: Cacheability::for(
                cacheKey: 'collections.data-table',
                cacheTags: CacheTags::of(RootCacheTag::COLLECTIONS, RootCacheTag::ACTIVITIES),
            ),
            render: fn (): string => $this->renderFor(),
        );

        return new JsonResponse($render->getContent() ?? '[]', headers: $render->getCacheHeaders(), json: true);
    }

    private function renderFor(): string
    {
        $unitSystem = $this->settingsRepository->appearance()->getUnitSystem();
        $rowTemplate = $this->twig->load('html/collection/collection-data-table-row.html.twig');
        $countries = $this->countries->getUsedInActivities();

        $dataTableRows = [];
        foreach ($this->collectionSummaryBuilder->build($this->collectionRepository->findAll()) as $summary) {
            $dataTableRows[] = DataTableRow::create(
                markup: $rowTemplate->render([
                    'summary' => $summary,
                    'countries' => $countries,
                ]),
                searchables: $summary->getSearchables(),
                filterables: $summary->getFilterables($unitSystem),
                sortValues: $summary->getSortables(),
                summables: [],
            );
        }

        return Json::encode($dataTableRows);
    }
}
