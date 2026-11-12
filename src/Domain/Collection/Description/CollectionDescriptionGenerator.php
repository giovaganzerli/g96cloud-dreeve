<?php

declare(strict_types=1);

namespace App\Domain\Collection\Description;

use App\Domain\Activity\EnrichedActivity;
use App\Domain\Collection\Collection;
use App\Domain\Collection\Statistics\CollectionStatistics;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\LoggerInterface;

final readonly class CollectionDescriptionGenerator
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param EnrichedActivity[] $activities
     */
    public function generate(Collection $collection, array $activities, CollectionStatistics $statistics): ?string
    {
        if (!$this->settingsRepository->integrations()->isAIIntegrationEnabled()) {
            return null;
        }

        try {
            $agent = Agent::make()
                ->setAiProvider($this->settingsRepository->integrations()->getAIProvider())
                ->setInstructions(new SystemMessage(<<<'PROMPT'
                    You are an assistant that writes short, evocative descriptions of sport adventures.
                    You receive the activities of a collection, the statistics that were calculated from them and a
                    fingerprint of the recorded GPS tracks. Write a description of 3 to 5 sentences that captures the
                    essence of the adventure: the places, the effort, the weather and the mood.
                    Only use the information that is given to you. Never invent facts, names or numbers.
                    Answer with the description text only. No preamble, no title, no markdown, no bullet points.
                    PROMPT))
                ->setTools([])
                ->setThreadId('collection-description-'.$collection->getId()->toUnprefixedString());

            $state = $agent->chat(new UserMessage($this->buildPrompt($collection, $activities, $statistics)));
            $content = trim((string) $state->getMessage()?->getContent());

            if ('' === $content) {
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not generate a collection description with the AI.', [
                'collectionId' => (string) $collection->getId(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * @param EnrichedActivity[] $activities
     */
    private function buildPrompt(Collection $collection, array $activities, CollectionStatistics $statistics): string
    {
        $lines = [];
        $lines[] = sprintf('Collection name: %s', $collection->getName());
        $lines[] = sprintf('Number of activities: %d', count($activities));
        $lines[] = '';
        $lines[] = 'Statistics:';
        foreach ($statistics as $statistic) {
            $lines[] = sprintf('- %s: %s', $statistic->getLabel(), $statistic->getValue());
        }
        $lines[] = '';
        $lines[] = 'Activities:';

        foreach ($activities as $enrichedActivity) {
            $activity = $enrichedActivity->getActivity();
            $lines[] = sprintf(
                '- %s (%s) on %s: %s, %s of ascent, %s of moving time, average speed %s.',
                $activity->getName(),
                $activity->getSportType()->value,
                $activity->getStartDate()->format('Y-m-d'),
                (string) $activity->getDistance(),
                (string) $activity->getElevation(),
                $activity->getMovingTimeFormatted(),
                (string) $activity->getAverageSpeed(),
            );

            $route = $this->routeFingerprint($activity->getEncodedPolyline());
            if (null !== $route) {
                $lines[] = sprintf('  Route fingerprint (lat/lng waypoints): %s', $route);
            }
        }

        return implode("\n", $lines);
    }

    private function routeFingerprint(?EncodedPolyline $polyline): ?string
    {
        if (!$polyline instanceof EncodedPolyline) {
            return null;
        }

        $coordinates = $polyline->decodeAndPairLatLng();
        if (count($coordinates) < 2) {
            return null;
        }

        $waypoints = [];
        $count = count($coordinates);
        for ($n = 0; $n < 7; ++$n) {
            $index = (int) round($count - 1 - ($count - 1) * ($n / 6));
            $waypoints[] = sprintf(
                '%.4f,%.4f',
                $coordinates[$index][0],
                $coordinates[$index][1]
            );
        }

        return implode(' -> ', $waypoints);
    }
}
