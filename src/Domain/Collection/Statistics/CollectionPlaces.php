<?php

declare(strict_types=1);

namespace App\Domain\Collection\Statistics;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\EnrichedActivity;

/**
 * Extracts the places an athlete passed through: the cities and the summits.
 *
 * The route geography only holds the city an activity started in, and there is no
 * gazetteer of peaks in the app. Both are therefore derived from what is known:
 * the reverse geocoded starting point and the name the athlete gave to the activity.
 */
final readonly class CollectionPlaces
{
    private const int MAXIMUM_NUMBER_OF_SUMMITS = 5;
    private const float MINIMUM_ELEVATION_GAIN_IN_METER = 200.0;

    /** @var string[] */
    private const array SUMMIT_KEYWORD_PATTERN = [
        // "Passo Giau e Laghi di Misurina" -> "Passo Giau"
        '/\b(pass[oi]|colle|forcella|sella|cima|monte|pizzo|picco|croda|sassalt[o]|alpe|punta|rocca|tacca|altipiano)\s+((?:\S+\s+){0,2}\S+)/iu',
    ];

    /** @var string[] */
    private const array SUMMIT_AFTER_PREPOSITION_PATTERN = [
        // "Salita all'Etna" -> "Etna"
        '/\b(?:salita|ascesa|scalinata)\s+(?:all[\'’]|alla|allo|alle|ai|al|a)\s*([\p{L}][\p{L}\d\'’\-]*(?:\s+[\p{L}][\p{L}\d\'’\-]*){0,2})/iu',
    ];

    /** @var string[] */
    private const array CONJUNCTION_WORDS = ['e', 'ed', 'y', 'und'];

    /** @var string[] */
    private const array NAME_TRAILING_WORDS = [
        'e', 'ed', 'y', 'und', 'a', 'al', 'alla', 'ai', 'alle', 'del', 'della', 'dei', 'di', 'dal',
        'dalla', 'in', 'sul', 'sulla', 'per', 'con', 'verso', 'attraverso', 'tra',
    ];

    /**
     * @param EnrichedActivity[] $activities
     *
     * @return list<string>
     */
    public function cities(array $activities): array
    {
        $cities = [];
        foreach ($activities as $enrichedActivity) {
            $activity = $enrichedActivity->getActivity();
            $city = $this->cityFor($activity);
            if (null === $city) {
                continue;
            }

            $key = mb_strtolower($city);
            if (isset($cities[$key])) {
                continue;
            }
            $cities[$key] = $city;
        }

        return array_values($cities);
    }

    /**
     * The main summits of the collection, the highest points of its routes.
     *
     * @param EnrichedActivity[]         $activities
     * @param array<string, list<float>> $altitudesPerActivityId
     *
     * @return list<array{name: string, altitudeInMeter: float, activityId: ActivityId}>
     */
    public function summits(array $activities, array $altitudesPerActivityId): array
    {
        $summits = [];
        foreach ($activities as $enrichedActivity) {
            $activity = $enrichedActivity->getActivity();
            $activityId = $activity->getId();
            $altitudes = $altitudesPerActivityId[(string) $activityId] ?? [];
            if ([] === $altitudes) {
                continue;
            }
            if ($activity->getElevation()->toFloat() < self::MINIMUM_ELEVATION_GAIN_IN_METER) {
                // Not a climb, so it is no summit.
                continue;
            }

            $summits[] = [
                'name' => $this->summitNameFor($activity),
                'altitudeInMeter' => max($altitudes),
                'activityId' => $activityId,
            ];
        }

        usort(
            $summits,
            static fn (array $a, array $b): int => $b['altitudeInMeter'] <=> $a['altitudeInMeter']
        );

        return array_slice($summits, 0, self::MAXIMUM_NUMBER_OF_SUMMITS);
    }

    private function cityFor(Activity $activity): ?string
    {
        $city = $activity->getRouteGeography()->getStartingPointCity();
        if (null !== $city && '' !== trim($city)) {
            return trim($city);
        }

        // Activity names are often prefixed with the place the activity started in.
        $name = $activity->getName();
        if (!str_contains($name, ' - ')) {
            return null;
        }

        $prefix = trim(strstr($name, ' - ', true) ?: '');
        if ('' === $prefix || str_contains($prefix, ' ')) {
            // Only single word prefixes are treated as a place, to avoid picking up
            // things like "Sunday - Long ride".
            return null;
        }

        return ctype_upper(mb_substr($prefix, 0, 1)) ? $prefix : null;
    }

    private function summitNameFor(Activity $activity): string
    {
        $name = $activity->getName();

        foreach (self::SUMMIT_AFTER_PREPOSITION_PATTERN as $pattern) {
            if (!preg_match($pattern, $name, $matches)) {
                continue;
            }

            $summit = $this->cleanWords((string) $matches[1]);
            if ('' !== $summit) {
                return $summit;
            }
        }

        foreach (self::SUMMIT_KEYWORD_PATTERN as $pattern) {
            if (!preg_match($pattern, $name, $matches)) {
                continue;
            }

            $summit = $this->cleanWords(trim((string) $matches[1]).' '.trim((string) $matches[2]));
            if ('' !== $summit) {
                return $summit;
            }
        }

        return $name;
    }

    /**
     * Removes the conjunctions and the trailing filler words from a name.
     */
    private function cleanWords(string $value): string
    {
        $words = preg_split('/\s+/', trim($value)) ?: [];

        foreach ($words as $index => $word) {
            if (!in_array(mb_strtolower($word), self::CONJUNCTION_WORDS, true)) {
                continue;
            }
            $words = array_slice($words, 0, $index);
            break;
        }

        while ([] !== $words) {
            $last = mb_strtolower(end($words));
            if (!in_array($last, self::NAME_TRAILING_WORDS, true)) {
                break;
            }
            array_pop($words);
        }

        return implode(' ', $words);
    }
}
