<?php

declare(strict_types=1);

namespace App\Domain\Segment\AddCustomSegment;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Geography\Country\RouteGeographyAnalyzer;
use App\Domain\Segment\Geometry\GpxSegmentTrackParser;
use App\Domain\Segment\LocalSegmentGeometry;
use App\Domain\Segment\Segment;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

final readonly class AddCustomSegmentCommandHandler implements CommandHandler
{
    private const int MIN_DISTANCE_IN_METERS = 100;
    private const int MAX_GRADIENT_WINDOW_IN_METERS = 50;

    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private SegmentRepository $segmentRepository,
        private RouteGeographyAnalyzer $routeGeographyAnalyzer,
        private GpxSegmentTrackParser $gpxParser,
        private LocalSegmentGeometry $localGeometry,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof AddCustomSegment);

        if (null !== $command->getContents()) {
            $this->addFromGpx($command);

            return;
        }

        $activityId = $command->getActivityId();
        if (!$activityId instanceof \App\Domain\Activity\ActivityId || !$this->activityRepository->exists($activityId)) {
            throw CouldNotProcessCommand::withReason('The selected activity does not exist.');
        }

        $activity = $this->activityRepository->find($activityId);
        $streams = $this->activityStreamRepository->findByActivityId($activityId);
        $latLng = array_values($streams->filterOnType(StreamType::LAT_LNG)?->getData() ?? []);
        $distance = array_values($streams->filterOnType(StreamType::DISTANCE)?->getData() ?? []);
        $altitude = array_values($streams->filterOnType(StreamType::ALTITUDE)?->getData() ?? []);

        if ([] === $latLng || [] === $distance) {
            throw CouldNotProcessCommand::withReason('The selected activity has no GPS data.');
        }

        $startIndex = $command->getStartIndex();
        $endIndex = $command->getEndIndex();
        if ($endIndex >= min(count($latLng), count($distance))) {
            throw CouldNotProcessCommand::withReason('The selected start and end are not part of the activity route.');
        }

        $segmentDistance = $distance[$endIndex] - $distance[$startIndex];
        if ($segmentDistance < self::MIN_DISTANCE_IN_METERS) {
            throw CouldNotProcessCommand::withReason(sprintf('A segment needs to be at least %d meters long.', self::MIN_DISTANCE_IN_METERS));
        }

        $averageGradient = null;
        $maxGradient = 0.0;
        if (count($altitude) > $endIndex) {
            $averageGradient = round(($altitude[$endIndex] - $altitude[$startIndex]) / $segmentDistance * 100, 1);
            $maxGradient = $averageGradient;
            $windowEndIndex = $startIndex;
            for ($index = $startIndex; $index <= $endIndex; ++$index) {
                while ($windowEndIndex <= $endIndex && $distance[$windowEndIndex] - $distance[$index] < self::MAX_GRADIENT_WINDOW_IN_METERS) {
                    ++$windowEndIndex;
                }
                if ($windowEndIndex > $endIndex) {
                    break;
                }
                $maxGradient = max(
                    $maxGradient,
                    round(($altitude[$windowEndIndex] - $altitude[$index]) / ($distance[$windowEndIndex] - $distance[$index]) * 100, 1),
                );
            }
        }

        $polyline = EncodedPolyline::fromCoordinates(array_slice($latLng, $startIndex, $endIndex - $startIndex + 1));
        $countryCode = null;
        if ($activity->getSportType()->supportsReverseGeocoding() && ($countryCodes = $this->routeGeographyAnalyzer->analyzeForPolyline($polyline))) {
            $countryCode = strtolower($countryCodes[0]);
        }

        $this->segmentRepository->add(Segment::createCustom(
            segmentId: SegmentId::random(),
            name: $command->getName(),
            sportType: $activity->getSportType(),
            distance: Meter::from($segmentDistance)->toKilometer(),
            maxGradient: $maxGradient,
            averageGradient: $averageGradient,
            isFavourite: $command->isFavourite(),
            countryCode: $countryCode,
            polyline: $polyline,
        ));
    }

    private function addFromGpx(AddCustomSegment $command): void
    {
        $contents = $command->getContents();
        $sportType = $command->getSportType();
        assert(null !== $contents && $sportType instanceof \App\Domain\Activity\SportType\SportType);
        $metrics = $this->gpxParser->metrics($contents);
        if ($metrics['distance'] < self::MIN_DISTANCE_IN_METERS) {
            throw CouldNotProcessCommand::withReason(sprintf('Il segmento deve essere lungo almeno %d metri.', self::MIN_DISTANCE_IN_METERS));
        }
        $polyline = $this->gpxParser->parse($contents);
        $countries = $this->routeGeographyAnalyzer->analyzeForPolyline($polyline);
        $segment = Segment::createCustom(
            segmentId: SegmentId::random(), name: $command->getName(), sportType: $sportType,
            distance: Meter::from(round($metrics['distance']))->toKilometer(), maxGradient: $metrics['maxGradient'],
            averageGradient: $metrics['averageGradient'], isFavourite: $command->isFavourite(),
            countryCode: [] !== $countries ? strtolower($countries[0]) : null, polyline: $polyline,
        );
        $this->segmentRepository->add($segment);
        $this->localGeometry->recordUploaded($segment, $metrics);
    }
}
