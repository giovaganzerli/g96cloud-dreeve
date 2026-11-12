<?php

declare(strict_types=1);

namespace App\Domain\Collection\Statistics;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIds;
use App\Domain\Activity\EnrichedActivity;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Activity\Surface\ActivitySurfaceRepository;
use App\Domain\Activity\Surface\SurfaceAnalysis;
use App\Domain\Collection\Collection;
use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Localisation\CountryNames;
use App\Infrastructure\Measurement\ProvideMeasurementFormats;
use App\Infrastructure\Time\Format\ProvideTimeFormats;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CollectionStatisticsCalculator
{
    use ProvideMeasurementFormats;
    use ProvideTimeFormats;

    public const string KEY_TOTAL_TIME = 'time.total';
    public const string KEY_MOVING_TIME = 'time.moving';
    public const string KEY_STOPPED_TIME = 'time.stopped';
    public const string KEY_TOTAL_DISTANCE = 'distance.total';
    public const string KEY_AVERAGE_DISTANCE = 'distance.average';
    public const string KEY_TOTAL_ASCENT = 'elevation.total';
    public const string KEY_MAX_ALTITUDE = 'altitude.max';
    public const string KEY_MIN_ALTITUDE = 'altitude.min';
    public const string KEY_AVERAGE_ALTITUDE = 'altitude.average';
    public const string KEY_AVERAGE_SPEED = 'speed.average';
    public const string KEY_MAX_SPEED = 'speed.max';
    public const string KEY_LONGEST_STAGE = 'stage.longest';
    public const string KEY_STAGE_WITH_MOST_ASCENT = 'stage.ascent';
    public const string KEY_FASTEST_STAGE = 'stage.fastest';
    public const string KEY_MAX_TEMPERATURE = 'temperature.max';
    public const string KEY_MIN_TEMPERATURE = 'temperature.min';
    public const string KEY_AVERAGE_TEMPERATURE = 'temperature.average';
    public const string KEY_COUNTRIES_VISITED = 'countries.visited';
    public const string KEY_MAIN_CITIES = 'geography.cities';
    public const string KEY_MAIN_SUMMITS = 'geography.summits';
    public const string KEY_DISTANCE_ON_ASPHALT = 'surface.paved';
    public const string KEY_DISTANCE_ON_UNPAVED = 'surface.unpaved';

    public function __construct(
        private ActivityStreamRepository $activityStreamRepository,
        private ActivitySurfaceRepository $activitySurfaceRepository,
        private SettingsRepository $settingsRepository,
        private TranslatorInterface $translator,
        private CountryNames $countryNames,
        private CollectionPlaces $collectionPlaces,
    ) {
    }

    /**
     * @param EnrichedActivity[] $activities
     */
    public function calculate(Collection $collection, array $activities): CollectionStatistics
    {
        $statistics = $this->calculateAutomatic($activities);

        // Apply the manually edited values.
        $overrides = $collection->getStatisticOverrides();
        $merged = CollectionStatistics::empty();
        foreach ($statistics as $statistic) {
            $merged->add(
                isset($overrides[$statistic->getKey()])
                    ? $statistic->withValue($overrides[$statistic->getKey()])
                    : $statistic
            );
        }

        // Add the custom statistics.
        foreach ($collection->getCustomStatistics() as $customStatistic) {
            $merged->add($customStatistic);
        }

        return $this->applyOrder($merged, $collection->getStatisticOrder());
    }

    /**
     * @param EnrichedActivity[] $activities
     */
    public function calculateAutomatic(array $activities): CollectionStatistics
    {
        $unitSystem = $this->settingsRepository->appearance()->getUnitSystem();
        $statistics = CollectionStatistics::empty();

        $totalDistanceInMeter = 0.0;
        $totalElevationInMeter = 0.0;
        $totalMovingTimeInSeconds = 0;
        $totalElapsedTimeInSeconds = 0;

        $longestStage = null;
        $stageWithMostAscent = null;
        $fastestStage = null;
        $longestStageDistance = -INF;
        $stageWithMostAscentElevation = -INF;
        $fastestStageSpeed = -INF;
        $maxSpeed = 0.0;

        $temperatures = [];
        $countryCodes = [];

        $altitudes = [];
        $altitudeSamples = 0;
        $altitudeSum = 0.0;
        /** @var array<string, list<float>> $altitudesPerActivityId */
        $altitudesPerActivityId = [];

        foreach ($activities as $enrichedActivity) {
            $activity = $enrichedActivity->getActivity();

            $totalDistanceInMeter += $activity->getDistance()->toMeter()->toFloat();
            $totalElevationInMeter += $activity->getElevation()->toFloat();
            $totalMovingTimeInSeconds += $activity->getMovingTimeInSeconds();
            $totalElapsedTimeInSeconds += $activity->getElapsedTimeInSeconds();

            if ($activity->getDistance()->toFloat() > $longestStageDistance) {
                $longestStage = $activity;
                $longestStageDistance = $activity->getDistance()->toFloat();
            }
            if ($activity->getElevation()->toFloat() > $stageWithMostAscentElevation) {
                $stageWithMostAscent = $activity;
                $stageWithMostAscentElevation = $activity->getElevation()->toFloat();
            }
            if ($activity->getAverageSpeed()->toFloat() > $fastestStageSpeed) {
                $fastestStage = $activity;
                $fastestStageSpeed = $activity->getAverageSpeed()->toFloat();
            }
            $maxSpeed = max($maxSpeed, $activity->getMaxSpeed()->toFloat());

            if (null !== ($temperature = $activity->getWeather()?->getTemperatureInCelsius())) {
                $temperatures[] = $temperature->toFloat();
            }

            $routeGeography = $activity->getRouteGeography();
            if (null !== ($countryCode = $routeGeography->getStartingPointCountryCode())) {
                $countryCodes[strtolower($countryCode)] = true;
            }
            foreach ($routeGeography->getPassedThroughCountries() as $passedThroughCountryCode) {
                $countryCodes[strtolower($passedThroughCountryCode)] = true;
            }

            $activityAltitudes = $this->altitudesFor($activity->getId());
            if ([] !== $activityAltitudes) {
                $altitudes = array_merge($altitudes, $activityAltitudes);
                $altitudeSamples += count($activityAltitudes);
                $altitudeSum += array_sum($activityAltitudes);
                $altitudesPerActivityId[(string) $activity->getId()] = $activityAltitudes;
            }
        }

        // Time.
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_TOTAL_TIME,
            label: $this->translator->trans('Total time'),
            value: $this->formatDurationAsHHMMSS($totalElapsedTimeInSeconds),
            icon: 'time',
            category: CollectionStatisticCategory::TIME,
            numericValue: (float) $totalElapsedTimeInSeconds,
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MOVING_TIME,
            label: $this->translator->trans('Moving time'),
            value: $this->formatDurationAsHHMMSS($totalMovingTimeInSeconds),
            icon: 'time',
            category: CollectionStatisticCategory::TIME,
            numericValue: (float) $totalMovingTimeInSeconds,
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_STOPPED_TIME,
            label: $this->translator->trans('Stopped time'),
            value: $this->formatDurationAsHHMMSS(max(0, $totalElapsedTimeInSeconds - $totalMovingTimeInSeconds)),
            icon: 'time',
            category: CollectionStatisticCategory::TIME,
            numericValue: (float) max(0, $totalElapsedTimeInSeconds - $totalMovingTimeInSeconds),
        ));

        // Distance.
        $distance = $unitSystem->distance($totalDistanceInMeter / 1000);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_TOTAL_DISTANCE,
            label: $this->translator->trans('Total distance'),
            value: $this->formatNumber($distance->toFloat(), 1),
            unit: $distance->getSymbol(),
            icon: 'distance',
            category: CollectionStatisticCategory::DISTANCE,
            numericValue: $distance->toFloat(),
        ));
        $averageDistance = $unitSystem->distance(
            [] === $activities ? 0 : $totalDistanceInMeter / 1000 / count($activities)
        );
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_AVERAGE_DISTANCE,
            label: $this->translator->trans('Average distance'),
            value: $this->formatNumber($averageDistance->toFloat(), 1),
            unit: $averageDistance->getSymbol(),
            icon: 'distance',
            category: CollectionStatisticCategory::DISTANCE,
            numericValue: $averageDistance->toFloat(),
        ));

        // Elevation.
        $elevation = $unitSystem->elevation($totalElevationInMeter);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_TOTAL_ASCENT,
            label: $this->translator->trans('Total ascent'),
            value: $this->formatNumber($elevation->toFloat(), 0),
            unit: $elevation->getSymbol(),
            icon: 'elevation',
            category: CollectionStatisticCategory::ELEVATION,
            numericValue: $elevation->toFloat(),
        ));

        if ([] !== $altitudes) {
            $maxAltitude = $unitSystem->elevation(max($altitudes));
            $minAltitude = $unitSystem->elevation(min($altitudes));
            $averageAltitude = $unitSystem->elevation($altitudeSum / $altitudeSamples);
        } else {
            $maxAltitude = $unitSystem->elevation(0);
            $minAltitude = $unitSystem->elevation(0);
            $averageAltitude = $unitSystem->elevation(0);
        }

        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MAX_ALTITUDE,
            label: $this->translator->trans('Max altitude'),
            value: $this->formatNumber($maxAltitude->toFloat(), 0),
            unit: $maxAltitude->getSymbol(),
            icon: 'elevation',
            category: CollectionStatisticCategory::ELEVATION,
            numericValue: $maxAltitude->toFloat(),
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MIN_ALTITUDE,
            label: $this->translator->trans('Min altitude'),
            value: $this->formatNumber($minAltitude->toFloat(), 0),
            unit: $minAltitude->getSymbol(),
            icon: 'elevation',
            category: CollectionStatisticCategory::ELEVATION,
            numericValue: $minAltitude->toFloat(),
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_AVERAGE_ALTITUDE,
            label: $this->translator->trans('Average altitude'),
            value: $this->formatNumber($averageAltitude->toFloat(), 0),
            unit: $averageAltitude->getSymbol(),
            icon: 'elevation',
            category: CollectionStatisticCategory::ELEVATION,
            numericValue: $averageAltitude->toFloat(),
        ));

        // Speed.
        $averageSpeed = $totalMovingTimeInSeconds > 0
            ? $unitSystem->speed($totalDistanceInMeter / 1000 / ($totalMovingTimeInSeconds / 3600))
            : $unitSystem->speed(0);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_AVERAGE_SPEED,
            label: $this->translator->trans('Average speed'),
            value: $this->formatNumber($averageSpeed->toFloat(), 1),
            unit: $averageSpeed->getSymbol(),
            icon: 'speed',
            category: CollectionStatisticCategory::SPEED,
            numericValue: $averageSpeed->toFloat(),
        ));
        $maxSpeedInDisplayUnit = $unitSystem->speed($maxSpeed);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MAX_SPEED,
            label: $this->translator->trans('Max speed'),
            value: $this->formatNumber($maxSpeedInDisplayUnit->toFloat(), 1),
            unit: $maxSpeedInDisplayUnit->getSymbol(),
            icon: 'speed',
            category: CollectionStatisticCategory::SPEED,
            numericValue: $maxSpeedInDisplayUnit->toFloat(),
        ));

        // Stages.
        $statistics->add($this->buildStageStatistic(
            key: self::KEY_LONGEST_STAGE,
            label: $this->translator->trans('Longest stage'),
            activity: $longestStage,
            value: $longestStage instanceof Activity
                ? sprintf(
                    '%s (%s %s)',
                    $longestStage->getName(),
                    $this->formatNumber($unitSystem->distance($longestStage->getDistance()->toFloat())->toFloat(), 1),
                    $unitSystem->distance($longestStage->getDistance()->toFloat())->getSymbol()
                )
                : '',
            numericValue: $longestStage instanceof Activity ? $longestStage->getDistance()->toFloat() : null,
        ));
        $statistics->add($this->buildStageStatistic(
            key: self::KEY_STAGE_WITH_MOST_ASCENT,
            label: $this->translator->trans('Stage with most ascent'),
            activity: $stageWithMostAscent,
            value: $stageWithMostAscent instanceof Activity
                ? sprintf(
                    '%s (%s %s)',
                    $stageWithMostAscent->getName(),
                    $this->formatNumber($unitSystem->elevation($stageWithMostAscent->getElevation()->toFloat())->toFloat(), 0),
                    $unitSystem->elevation($stageWithMostAscent->getElevation()->toFloat())->getSymbol()
                )
                : '',
            numericValue: $stageWithMostAscent instanceof Activity ? $stageWithMostAscent->getElevation()->toFloat() : null,
        ));
        $statistics->add($this->buildStageStatistic(
            key: self::KEY_FASTEST_STAGE,
            label: $this->translator->trans('Fastest stage'),
            activity: $fastestStage,
            value: $fastestStage instanceof Activity
                ? sprintf(
                    '%s (%s %s)',
                    $fastestStage->getName(),
                    $this->formatNumber($unitSystem->speed($fastestStage->getAverageSpeed()->toFloat())->toFloat(), 1),
                    $unitSystem->speed($fastestStage->getAverageSpeed()->toFloat())->getSymbol()
                )
                : '',
            numericValue: $fastestStage instanceof Activity ? $fastestStage->getAverageSpeed()->toFloat() : null,
        ));

        // Temperature.
        $maxTemperature = $unitSystem->temperature(0);
        $minTemperature = $unitSystem->temperature(0);
        $averageTemperature = $unitSystem->temperature(0);
        if ([] !== $temperatures) {
            $maxTemperature = $unitSystem->temperature(max($temperatures));
            $minTemperature = $unitSystem->temperature(min($temperatures));
            $averageTemperature = $unitSystem->temperature(array_sum($temperatures) / count($temperatures));
        }

        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MAX_TEMPERATURE,
            label: $this->translator->trans('Max temperature'),
            value: $this->formatNumber($maxTemperature->toFloat(), 1),
            unit: $maxTemperature->getSymbol(),
            icon: 'sun',
            category: CollectionStatisticCategory::WEATHER,
            numericValue: $maxTemperature->toFloat(),
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MIN_TEMPERATURE,
            label: $this->translator->trans('Min temperature'),
            value: $this->formatNumber($minTemperature->toFloat(), 1),
            unit: $minTemperature->getSymbol(),
            icon: 'sun',
            category: CollectionStatisticCategory::WEATHER,
            numericValue: $minTemperature->toFloat(),
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_AVERAGE_TEMPERATURE,
            label: $this->translator->trans('Average temperature'),
            value: $this->formatNumber($averageTemperature->toFloat(), 1),
            unit: $averageTemperature->getSymbol(),
            icon: 'sun',
            category: CollectionStatisticCategory::WEATHER,
            numericValue: $averageTemperature->toFloat(),
        ));

        // Geography.
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_COUNTRIES_VISITED,
            label: $this->translator->trans('Countries visited'),
            value: $this->countryNames(array_keys($countryCodes)),
            icon: 'globe',
            category: CollectionStatisticCategory::GEOGRAPHY,
            numericValue: (float) count($countryCodes),
        ));
        $cities = $this->collectionPlaces->cities($activities);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MAIN_CITIES,
            label: $this->translator->trans('Main cities'),
            value: implode(', ', $cities),
            icon: 'globe',
            category: CollectionStatisticCategory::GEOGRAPHY,
            numericValue: (float) count($cities),
        ));
        $summits = $this->collectionPlaces->summits($activities, $altitudesPerActivityId);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_MAIN_SUMMITS,
            label: $this->translator->trans('Main summits'),
            value: implode(', ', array_map(
                fn (array $summit): string => sprintf(
                    '%s (%s %s)',
                    $summit['name'],
                    $this->formatNumber($unitSystem->elevation($summit['altitudeInMeter'])->toFloat(), 0),
                    $unitSystem->elevation($summit['altitudeInMeter'])->getSymbol()
                ),
                $summits
            )),
            icon: 'elevation',
            category: CollectionStatisticCategory::GEOGRAPHY,
            numericValue: (float) count($summits),
        ));

        // Surfaces.
        $surfaceAnalysis = $this->surfaceAnalysisFor($activities);
        $pavedDistance = $unitSystem->distance($surfaceAnalysis->getPavedDistanceInMeter() / 1000);
        $unpavedDistance = $unitSystem->distance($surfaceAnalysis->getUnpavedDistanceInMeter() / 1000);
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_DISTANCE_ON_ASPHALT,
            label: $this->translator->trans('Distance on asphalt'),
            value: $this->formatNumber($pavedDistance->toFloat(), 1),
            unit: $pavedDistance->getSymbol(),
            icon: 'ruler',
            category: CollectionStatisticCategory::SURFACE,
            numericValue: $pavedDistance->toFloat(),
        ));
        $statistics->add(CollectionStatistic::create(
            key: self::KEY_DISTANCE_ON_UNPAVED,
            label: $this->translator->trans('Distance on unpaved roads'),
            value: $this->formatNumber($unpavedDistance->toFloat(), 1),
            unit: $unpavedDistance->getSymbol(),
            icon: 'ruler',
            category: CollectionStatisticCategory::SURFACE,
            numericValue: $unpavedDistance->toFloat(),
        ));

        return $statistics;
    }

    private function buildStageStatistic(
        string $key,
        string $label,
        ?Activity $activity,
        string $value,
        ?float $numericValue,
    ): CollectionStatistic {
        return CollectionStatistic::create(
            key: $key,
            label: $label,
            value: $value,
            icon: 'route',
            category: CollectionStatisticCategory::STAGES,
            numericValue: $numericValue,
            linkActivityId: $activity?->getId(),
        );
    }

    /**
     * @param string[] $countryCodes
     */
    private function countryNames(array $countryCodes): string
    {
        sort($countryCodes);

        return implode(', ', $this->countryNames->names($countryCodes));
    }

    /**
     * @return list<float>
     */
    private function altitudesFor(ActivityId $activityId): array
    {
        $streams = $this->activityStreamRepository->findByActivityId($activityId);
        $altitude = $streams->filterOnType(StreamType::ALTITUDE)?->getData() ?? [];

        $altitudes = [];
        foreach ($altitude as $value) {
            if (is_numeric($value)) {
                $altitudes[] = (float) $value;
            }
        }

        return $altitudes;
    }

    /**
     * @param EnrichedActivity[] $activities
     */
    private function surfaceAnalysisFor(array $activities): SurfaceAnalysis
    {
        $activityIds = ActivityIds::fromArray(array_map(
            static fn (EnrichedActivity $enrichedActivity): ActivityId => $enrichedActivity->getActivity()->getId(),
            $activities
        ));

        $total = SurfaceAnalysis::empty();
        foreach ($this->activitySurfaceRepository->findByActivityIds($activityIds) as $activitySurface) {
            $total = $total->add($activitySurface->getAnalysis());
        }

        return $total;
    }

    /**
     * @param string[] $order
     */
    private function applyOrder(CollectionStatistics $statistics, array $order): CollectionStatistics
    {
        if ([] === $order) {
            return $statistics;
        }

        $ordered = CollectionStatistics::empty();
        $remaining = $statistics->toArray();

        foreach ($order as $key) {
            foreach ($remaining as $index => $statistic) {
                if ($statistic->getKey() !== $key) {
                    continue;
                }
                $ordered->add($statistic);
                unset($remaining[$index]);
                break;
            }
        }

        foreach ($remaining as $statistic) {
            $ordered->add($statistic);
        }

        return $ordered;
    }
}
