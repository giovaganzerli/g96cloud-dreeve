<?php

declare(strict_types=1);

namespace App\Application\Import\CalculateActivityMetrics\Pipeline;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\Surface\ActivitySurface;
use App\Domain\Activity\Surface\ActivitySurfaceRepository;
use App\Domain\Activity\Surface\SurfaceAnalyzer;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Console\ProgressIndicator;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 25)]
final readonly class CalculateActivitySurfaces implements CalculateActivityMetricsStep
{
    private const int MAX_CONSECUTIVE_FAILURES = 5;

    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivitySurfaceRepository $activitySurfaceRepository,
        private SurfaceAnalyzer $surfaceAnalyzer,
        private SettingsRepository $settingsRepository,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function process(OutputInterface $output): void
    {
        if (!$this->settingsRepository->integrations()->isOpenStreetMapIntegrationEnabled()) {
            return;
        }

        $activityIds = $this->activitySurfaceRepository->findActivityIdsThatNeedSurfaceAnalysis();
        if ($activityIds->isEmpty()) {
            return;
        }

        $progressIndicator = new ProgressIndicator($output);
        $progressIndicator->start('=> Analyzed the surfaces of 0 activities');

        $analyzedActivityCount = 0;
        $failureCount = 0;

        foreach ($activityIds as $activityId) {
            if ($failureCount >= self::MAX_CONSECUTIVE_FAILURES) {
                // The OpenStreetMap API seems to be unreachable, stop hammering it.
                break;
            }

            $polyline = $this->activityRepository->find($activityId)->getEncodedPolyline();
            if (!$polyline instanceof EncodedPolyline) {
                continue;
            }

            try {
                $analysis = $this->surfaceAnalyzer->analyze($polyline);
            } catch (\Throwable $e) {
                ++$failureCount;
                $this->logger->warning('Could not analyze the surface of an activity.', [
                    'activityId' => (string) $activityId,
                    'exception' => $e,
                ]);

                continue;
            }

            $this->activitySurfaceRepository->add(new ActivitySurface(
                activityId: $activityId,
                pavedDistanceInMeter: $analysis->getPavedDistanceInMeter(),
                unpavedDistanceInMeter: $analysis->getUnpavedDistanceInMeter(),
                unknownDistanceInMeter: $analysis->getUnknownDistanceInMeter(),
                analyzedOn: $this->clock->getCurrentDateTimeImmutable(),
            ));

            ++$analyzedActivityCount;
            $progressIndicator->updateMessage(sprintf(
                '=> Analyzed the surfaces of %d activities',
                $analyzedActivityCount
            ));
        }

        $progressIndicator->finish(sprintf(
            '=> Analyzed the surfaces of %d activities',
            $analyzedActivityCount
        ));
    }
}
