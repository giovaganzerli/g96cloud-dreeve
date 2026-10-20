<?php

declare(strict_types=1);

namespace App\Controller\Admin\Activity;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ApplyActivityRecalculation\ApplyActivityRecalculation;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\Recalculate\ActivityRecalculation;
use App\Domain\Activity\Recalculate\BestEffortComparison;
use App\Domain\Activity\Recalculate\CouldNotRecalculateActivityMetrics;
use App\Domain\Activity\Recalculate\PauseDetectionParameters;
use App\Domain\Activity\Recalculate\SegmentEffortComparison;
use App\Domain\Activity\RecalculateActivityMetrics\RecalculateActivityMetrics;
use App\Domain\Activity\RevertActivityMetrics\RevertActivityMetrics;
use App\Domain\Integration\Elevation\OpenMeteo\OpenMeteoElevationApiCallHasFailed;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Http\HtmlResponse;
use App\Infrastructure\Measurement\Length\Meter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[AsController]
final readonly class RecalculateActivityMetricsRequestHandler
{
    public function __construct(
        private Environment $twig,
        private ActivityRepository $activityRepository,
        private ActivityBestEffortRepository $activityBestEffortRepository,
        private SegmentEffortRepository $segmentEffortRepository,
        private SettingsRepository $settingsRepository,
        private CommandBus $commands,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route(path: '/admin/activities/{activityId}/recalculate', name: 'admin_recalculate_activity_metrics', requirements: ['activityId' => 'activity-[^/]+'], methods: ['GET', 'POST'], priority: 10)]
    public function handle(Request $request): Response
    {
        $activityId = ActivityId::fromString($request->attributes->getString('activityId'));
        $activity = $this->activityRepository->find($activityId);

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('dispatch-command', $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Token CSRF non valido.');
            }

            $action = $request->request->getString('action');

            if ('revert' === $action) {
                $this->commands->dispatch(RevertActivityMetrics::fromPayload(['activityId' => (string) $activity->getId()]));

                return new RedirectResponse($this->urls->generate('activity', ['activityId' => $activity->getId()]), Response::HTTP_SEE_OTHER);
            }

            if ('apply' === $action) {
                $this->commands->dispatch(ApplyActivityRecalculation::fromPayload([
                    'activityId' => (string) $activity->getId(),
                    'metrics' => $request->request->all('metrics'),
                    'recalculateBestEfforts' => $request->request->getBoolean('recalculateBestEfforts'),
                    'recalculateSegmentEfforts' => $request->request->getBoolean('recalculateSegmentEfforts'),
                ]));

                return new RedirectResponse($this->urls->generate('activity', ['activityId' => $activity->getId()]), Response::HTTP_SEE_OTHER);
            }

            // Recalculate everything and show the result, waiting for confirmation.
            try {
                $this->commands->dispatch(RecalculateActivityMetrics::fromPayload(['activityId' => (string) $activity->getId()]));
            } catch (CouldNotRecalculateActivityMetrics|OpenMeteoElevationApiCallHasFailed $exception) {
                $error = $exception->getMessage();
            }

            if (null === $error) {
                return new RedirectResponse($this->urls->generate('admin_recalculate_activity_metrics', ['activityId' => $activity->getId()]), Response::HTTP_SEE_OTHER);
            }
        }

        $pending = $activity->getPendingRecalculation();
        $sportType = $activity->getSportType();

        $response = new HtmlResponse($this->twig->render('html/admin/page/activity/recalculate-activity-metrics.html.twig', [
            'activity' => $activity,
            'pending' => $pending,
            'hasPending' => $pending instanceof ActivityRecalculation,
            'bestEffortComparisons' => $this->buildBestEffortComparisons($activity, $pending),
            'segmentEffortComparisons' => $this->buildSegmentEffortComparisons($activity, $pending),
            'sportType' => $sportType,
            'pauseDetectionParameters' => PauseDetectionParameters::for($sportType),
            'error' => $error,
            'unitSystem' => $this->settingsRepository->appearance()->getUnitSystem(),
        ]));

        return $response->setStatusCode(null !== $error ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    /**
     * @return list<BestEffortComparison>
     */
    private function buildBestEffortComparisons(Activity $activity, ?ActivityRecalculation $pending): array
    {
        $current = [];
        foreach ($this->activityBestEffortRepository->findAllByActivity($activity->getId()) as $bestEffort) {
            $current[$bestEffort->getDistanceInMeter()->toInt()] = $bestEffort->getTimeInSeconds();
        }

        $comparisons = [];
        foreach ($pending?->getBestEfforts() ?? [] as $bestEffort) {
            $distance = $bestEffort['distanceInMeter'];
            $comparisons[] = new BestEffortComparison(
                distance: Meter::from($distance),
                currentTimeInSeconds: $current[$distance] ?? null,
                recalculatedTimeInSeconds: $bestEffort['timeInSeconds'],
            );
        }

        return $comparisons;
    }

    /**
     * @return list<SegmentEffortComparison>
     */
    private function buildSegmentEffortComparisons(Activity $activity, ?ActivityRecalculation $pending): array
    {
        $recalculated = [];
        foreach ($pending?->getSegmentEfforts() ?? [] as $segmentEffort) {
            $recalculated[$segmentEffort['segmentEffortId']] = $segmentEffort['elapsedTimeInSeconds'];
        }

        $comparisons = [];
        foreach ($this->segmentEffortRepository->findByActivityId($activity->getId()) as $segmentEffort) {
            $id = $segmentEffort->getId()->toUnprefixedString();
            $comparisons[] = new SegmentEffortComparison(
                id: $id,
                name: $segmentEffort->getName(),
                currentElapsedTimeInSeconds: $segmentEffort->getElapsedTimeInSeconds(),
                recalculatedElapsedTimeInSeconds: $recalculated[$id] ?? null,
            );
        }

        return $comparisons;
    }
}
