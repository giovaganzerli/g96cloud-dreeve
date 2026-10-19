<?php

declare(strict_types=1);

namespace App\Controller\Admin\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SearchActivitySegments\SearchActivitySegments;
use App\Infrastructure\Http\HtmlResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class SearchActivitySegmentsFormRequestHandler
{
    public function __construct(private Environment $twig, private ActivityRepository $activityRepository)
    {
    }

    #[Route(path: '/admin/activities/{activityId}/search-segments', name: 'admin_search_activity_segments', methods: ['GET'], priority: 10)]
    public function handle(string $activityId): HtmlResponse
    {
        $activity = $this->activityRepository->find(ActivityId::fromString($activityId));
        if (ImportSource::MANUAL === $activity->getImportSource()) {
            throw new NotFoundHttpException();
        }

        return new HtmlResponse($this->twig->render('html/admin/page/activity/search-segments.html.twig', [
            'activity' => $activity,
            'dispatchCommand' => SearchActivitySegments::getCommandName(),
        ]));
    }
}
