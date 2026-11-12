<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Surface;

use App\Domain\Activity\Surface\SurfaceAnalyzer;
use App\Domain\Integration\OpenStreetMap\OpenStreetMapWay;
use App\Domain\Integration\OpenStreetMap\OverpassApi;
use App\Infrastructure\ValueObject\Geography\BoundingBox;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

class SurfaceAnalyzerTest extends \PHPUnit\Framework\TestCase
{
    public function testItCalculatesTheDistanceOnPavedAndUnpavedRoads(): void
    {
        // A straight route of roughly 1.1 km from north to south.
        $polyline = EncodedPolyline::fromCoordinates([
            [50.0000, 4.0000],
            [49.9950, 4.0000],
            [49.9900, 4.0000],
        ]);

        $overpassApi = new FakeOverpassApi([
            // The first half of the route is a paved road.
            new OpenStreetMapWay(
                id: 1,
                geometry: [[50.0000, 4.0000], [49.9950, 4.0000]],
                tags: ['highway' => 'residential'],
            ),
            // The second half of the route is an unpaved track.
            new OpenStreetMapWay(
                id: 2,
                geometry: [[49.9950, 4.0000], [49.9900, 4.0000]],
                tags: ['highway' => 'track', 'tracktype' => 'grade3'],
            ),
        ]);

        $analyzer = new SurfaceAnalyzer($overpassApi);
        $analysis = $analyzer->analyze($polyline);

        $this->assertGreaterThan(400, $analysis->getPavedDistanceInMeter());
        $this->assertLessThan(700, $analysis->getPavedDistanceInMeter());
        $this->assertGreaterThan(400, $analysis->getUnpavedDistanceInMeter());
        $this->assertLessThan(700, $analysis->getUnpavedDistanceInMeter());
        $this->assertEquals(0.0, $analysis->getUnknownDistanceInMeter());
    }

    public function testItMarksTheRouteAsUnknownWhenThereAreNoRoads(): void
    {
        $polyline = EncodedPolyline::fromCoordinates([
            [50.0000, 4.0000],
            [49.9950, 4.0000],
        ]);

        $analyzer = new SurfaceAnalyzer(new FakeOverpassApi([]));
        $analysis = $analyzer->analyze($polyline);

        $this->assertEquals(0.0, $analysis->getPavedDistanceInMeter());
        $this->assertEquals(0.0, $analysis->getUnpavedDistanceInMeter());
        $this->assertGreaterThan(500, $analysis->getUnknownDistanceInMeter());
    }

    public function testItClassifiesTheSurfaceByItsTags(): void
    {
        $polyline = EncodedPolyline::fromCoordinates([
            [50.0000, 4.0000],
            [49.9950, 4.0000],
        ]);

        $analyzer = new SurfaceAnalyzer(new FakeOverpassApi([
            new OpenStreetMapWay(
                id: 1,
                geometry: [[50.0000, 4.0000], [49.9950, 4.0000]],
                tags: ['highway' => 'residential', 'surface' => 'gravel'],
            ),
        ]));

        $analysis = $analyzer->analyze($polyline);

        $this->assertGreaterThan(0, $analysis->getUnpavedDistanceInMeter());
        $this->assertEquals(0.0, $analysis->getPavedDistanceInMeter());
    }
}

final class FakeOverpassApi implements OverpassApi
{
    /**
     * @param list<OpenStreetMapWay> $ways
     */
    public function __construct(
        private array $ways,
    ) {
    }

    public function getHighwaysIn(BoundingBox $boundingBox): array
    {
        return $this->ways;
    }
}
