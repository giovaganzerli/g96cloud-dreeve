<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

use App\Domain\Integration\OpenStreetMap\OpenStreetMapWay;
use App\Domain\Integration\OpenStreetMap\OverpassApi;
use App\Infrastructure\ValueObject\Geography\BoundingBox;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use App\Infrastructure\ValueObject\Geography\GeoDistance;
use App\Infrastructure\ValueObject\Geography\Polyline;

final readonly class SurfaceAnalyzer
{
    private const float TOLERANCE_IN_METER = 25.0;
    private const float MAX_CHUNK_LENGTH_IN_METER = 10_000.0;
    private const float BBOX_MARGIN_IN_DEGREES = 0.003;
    private const float DENSIFY_STEP_IN_DEGREES = 0.0002;

    /** @var string[] */
    private const array PAVED_SURFACES = [
        'asphalt', 'paved', 'chipseal', 'tarmac', 'concrete', 'concrete:lanes', 'concrete:plates',
        'paving_stones', 'sett', 'cobblestone', 'unhewn_cobblestone', 'brick', 'metal', 'wood', 'rubber',
    ];

    /** @var string[] */
    private const array UNPAVED_SURFACES = [
        'gravel', 'fine_gravel', 'pebblestone', 'dirt', 'earth', 'soil', 'grass', 'grass_paver', 'sand',
        'mud', 'ground', 'compacted', 'unpaved', 'rock', 'ice', 'snow', 'salt', 'clay', 'woodchips', 'gravel_turf',
    ];

    /** @var string[] */
    private const array UNPAVED_HIGHWAYS = [
        'track', 'path', 'footway', 'bridleway', 'steps', 'trail', 'corridor',
    ];

    /** @var string[] */
    private const array PAVED_HIGHWAYS = [
        'motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link', 'secondary',
        'secondary_link', 'tertiary', 'tertiary_link', 'unclassified', 'residential', 'living_street',
        'service', 'road', 'busway', 'raceway',
    ];

    public function __construct(
        private OverpassApi $overpassApi,
    ) {
    }

    public function analyze(EncodedPolyline $polyline): SurfaceAnalysis
    {
        $coordinates = Polyline::fromEncodedPolyline($polyline)
            ->sanitize()
            ->densify(maxStepInDegrees: self::DENSIFY_STEP_IN_DEGREES)
            ->getLatLngCoordinates();

        if (count($coordinates) < 2) {
            return SurfaceAnalysis::empty();
        }

        $analysis = SurfaceAnalysis::empty();
        foreach ($this->chunk($coordinates) as $chunk) {
            $analysis = $analysis->add($this->analyzeChunk($chunk));
        }

        return $analysis;
    }

    /**
     * @param list<array{float, float}> $coordinates
     *
     * @return list<list<array{float, float}>>
     */
    private function chunk(array $coordinates): array
    {
        $chunks = [];
        $chunk = [];
        $chunkLength = 0.0;

        foreach ($coordinates as $coordinate) {
            if ([] !== $chunk) {
                $previous = $chunk[count($chunk) - 1];
                $chunkLength += GeoDistance::inMeter(
                    $previous[0],
                    $previous[1],
                    $coordinate[0],
                    $coordinate[1],
                );
            }
            $chunk[] = $coordinate;

            if ($chunkLength >= self::MAX_CHUNK_LENGTH_IN_METER) {
                $chunks[] = $chunk;
                $chunk = [];
                $chunkLength = 0.0;
            }
        }

        if (count($chunk) > 1) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @param list<array{float, float}> $chunk
     */
    private function analyzeChunk(array $chunk): SurfaceAnalysis
    {
        $ways = $this->overpassApi->getHighwaysIn($this->boundingBoxFor($chunk));
        if ([] === $ways) {
            return $this->allUnknown($chunk);
        }

        $wayData = [];
        foreach ($ways as $way) {
            $wayData[] = [
                'way' => $way,
                'type' => $this->classify($way),
                'bbox' => $this->boundingBoxFor($way->getGeometry()),
            ];
        }

        $distances = [
            SurfaceType::PAVED->value => 0.0,
            SurfaceType::UNPAVED->value => 0.0,
            SurfaceType::UNKNOWN->value => 0.0,
        ];

        /** @var array<string, string|null> $types The surface type of every route point. */
        $types = [];
        foreach ($chunk as $coordinate) {
            $types[] = $this->closestSurfaceType($coordinate, $wayData);
        }

        for ($index = 1, $count = count($chunk); $index < $count; ++$index) {
            $distance = GeoDistance::inMeter(
                $chunk[$index - 1][0],
                $chunk[$index - 1][1],
                $chunk[$index][0],
                $chunk[$index][1],
            );

            $previousType = $types[$index - 1] ?? null;
            $type = $types[$index] ?? null;
            if (null === $previousType || null === $type) {
                $type = SurfaceType::UNKNOWN->value;
            } elseif ($type !== $previousType) {
                // The surface changes halfway the segment, it counts towards the surface it started on.
                $type = $previousType;
            }

            $distances[$type] += $distance;
        }

        return new SurfaceAnalysis(
            $distances[SurfaceType::PAVED->value],
            $distances[SurfaceType::UNPAVED->value],
            $distances[SurfaceType::UNKNOWN->value],
        );
    }

    /**
     * @param list<array{float, float}> $chunk
     */
    private function allUnknown(array $chunk): SurfaceAnalysis
    {
        $distance = 0.0;
        for ($index = 1, $count = count($chunk); $index < $count; ++$index) {
            $distance += GeoDistance::inMeter(
                $chunk[$index - 1][0],
                $chunk[$index - 1][1],
                $chunk[$index][0],
                $chunk[$index][1],
            );
        }

        return new SurfaceAnalysis(0.0, 0.0, $distance);
    }

    /**
     * @param array{float, float}                                                 $coordinate
     * @param list<array{way: OpenStreetMapWay, type: string, bbox: BoundingBox}> $wayData
     */
    private function closestSurfaceType(array $coordinate, array $wayData): ?string
    {
        $closestDistance = self::TOLERANCE_IN_METER;
        $closestType = null;

        foreach ($wayData as $data) {
            $bbox = $data['bbox'];
            if ($coordinate[0] < $bbox->getMinLat() - 0.001
                || $coordinate[0] > $bbox->getMaxLat() + 0.001
                || $coordinate[1] < $bbox->getMinLng() - 0.001
                || $coordinate[1] > $bbox->getMaxLng() + 0.001) {
                continue;
            }

            $distance = $this->distanceToWay($coordinate, $data['way']);
            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestType = $data['type'];
            }
        }

        return $closestType;
    }

    /**
     * @param array{float, float} $coordinate
     */
    private function distanceToWay(array $coordinate, OpenStreetMapWay $way): float
    {
        $geometry = $way->getGeometry();
        $closest = INF;

        for ($index = 1, $count = count($geometry); $index < $count; ++$index) {
            $distance = GeoDistance::inMeterToSegment(
                $coordinate[0],
                $coordinate[1],
                $geometry[$index - 1][0],
                $geometry[$index - 1][1],
                $geometry[$index][0],
                $geometry[$index][1],
            );
            if ($distance < $closest) {
                $closest = $distance;
            }
        }

        return $closest;
    }

    private function classify(OpenStreetMapWay $way): string
    {
        $surface = $way->getTag('surface');
        if (null !== $surface) {
            if (in_array($surface, self::PAVED_SURFACES, true)) {
                return SurfaceType::PAVED->value;
            }
            if (in_array($surface, self::UNPAVED_SURFACES, true)) {
                return SurfaceType::UNPAVED->value;
            }
        }

        $trackType = $way->getTag('tracktype');
        if (null !== $trackType) {
            return 'grade1' === $trackType ? SurfaceType::PAVED->value : SurfaceType::UNPAVED->value;
        }

        $highway = $way->getTag('highway');
        if (null !== $highway) {
            if (in_array($highway, self::UNPAVED_HIGHWAYS, true)) {
                return SurfaceType::UNPAVED->value;
            }
            if (in_array($highway, self::PAVED_HIGHWAYS, true)) {
                return SurfaceType::PAVED->value;
            }
        }

        return SurfaceType::UNKNOWN->value;
    }

    /**
     * @param list<array{float, float}> $coordinates
     */
    private function boundingBoxFor(array $coordinates): BoundingBox
    {
        $minLat = $maxLat = $coordinates[0][0];
        $minLng = $maxLng = $coordinates[0][1];

        foreach ($coordinates as $coordinate) {
            $minLat = min($minLat, $coordinate[0]);
            $maxLat = max($maxLat, $coordinate[0]);
            $minLng = min($minLng, $coordinate[1]);
            $maxLng = max($maxLng, $coordinate[1]);
        }

        return BoundingBox::fromArray([
            $minLng - self::BBOX_MARGIN_IN_DEGREES,
            $minLat - self::BBOX_MARGIN_IN_DEGREES,
            $maxLng + self::BBOX_MARGIN_IN_DEGREES,
            $maxLat + self::BBOX_MARGIN_IN_DEGREES,
        ]);
    }
}
