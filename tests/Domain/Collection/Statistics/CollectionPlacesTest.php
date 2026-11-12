<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\Statistics;

use App\Domain\Collection\Statistics\CollectionPlaces;
use App\Infrastructure\Measurement\Length\Meter;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\TestCase;

class CollectionPlacesTest extends TestCase
{
    public function testItExtractsTheCitiesFromTheRouteGeography(): void
    {
        $places = new CollectionPlaces();

        $cities = $places->cities([
            $this->enrichedActivity('1', 'Morning ride', 'Cortina d\'Ampezzo'),
            $this->enrichedActivity('2', 'Afternoon ride', 'Cortina d\'Ampezzo'),
            $this->enrichedActivity('3', 'Evening ride', 'Bolzano'),
        ]);

        $this->assertEquals(['Cortina d\'Ampezzo', 'Bolzano'], $cities);
    }

    public function testItFallsBackToTheActivityNameForTheCity(): void
    {
        $places = new CollectionPlaces();

        $cities = $places->cities([
            $this->enrichedActivity('1', 'Trieste - Morning ride', null),
            $this->enrichedActivity('2', 'Bolzano - Evening ride', null),
        ]);

        $this->assertEquals(['Trieste', 'Bolzano'], $cities);
    }

    public function testItExtractsTheSummitsFromTheActivityNames(): void
    {
        $places = new CollectionPlaces();

        $summits = $places->summits(
            [
                $this->enrichedActivity('1', 'Passo Giau e Laghi di Misurina', 'Cortina'),
                $this->enrichedActivity('2', 'Salita all\'Etna', 'Nicolosi'),
            ],
            [
                'activity-1' => [1000.0, 1850.0, 1200.0],
                'activity-2' => [500.0, 3200.0],
            ]
        );

        $this->assertCount(2, $summits);
        // The summits are sorted by altitude, so the Etna comes first.
        $this->assertEquals('Etna', $summits[0]['name']);
        $this->assertEquals(3200.0, $summits[0]['altitudeInMeter']);
        $this->assertEquals('Passo Giau', $summits[1]['name']);
        $this->assertEquals(1850.0, $summits[1]['altitudeInMeter']);
    }

    public function testItSortsTheSummitsByAltitude(): void
    {
        $places = new CollectionPlaces();

        $summits = $places->summits(
            [
                $this->enrichedActivity('1', 'Colle delle Finestre', 'Arezzo'),
                $this->enrichedActivity('2', 'Monte Amiata', 'Abbadia San Salvatore'),
            ],
            [
                'activity-1' => [500.0, 1200.0],
                'activity-2' => [500.0, 1800.0],
            ]
        );

        $this->assertEquals('Monte Amiata', $summits[0]['name']);
        $this->assertEquals('Colle delle Finestre', $summits[1]['name']);
    }

    public function testItIgnoresTheActivitiesWithoutAClimb(): void
    {
        $places = new CollectionPlaces();

        $summits = $places->summits(
            [$this->enrichedActivity('1', 'Easy spin', 'Bolzano', elevation: 50)],
            ['activity-1' => [200.0, 210.0]]
        );

        $this->assertEquals([], $summits);
    }

    public function testItIgnoresTheActivitiesWithoutAltitudeData(): void
    {
        $places = new CollectionPlaces();

        $summits = $places->summits(
            [$this->enrichedActivity('1', 'Passo Giau', 'Cortina')],
            []
        );

        $this->assertEquals([], $summits);
    }

    private function enrichedActivity(string $id, string $name, ?string $city, int $elevation = 1000): \App\Domain\Activity\EnrichedActivity
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withActivityId(\App\Domain\Activity\ActivityId::fromUnprefixed($id))
            ->withName($name)
            ->withElevation(Meter::from($elevation))
            ->withRouteGeography(
                null === $city
                    ? \App\Domain\Activity\Route\RouteGeography::create([])
                    : \App\Domain\Activity\Route\RouteGeography::create([
                        'country_code' => 'it',
                        'city' => $city,
                    ])
            )
            ->build();

        return \App\Domain\Activity\EnrichedActivity::fromState(
            $activity,
            null,
            null,
            null,
            \App\Domain\Activity\Stream\PowerOutputs::empty(),
        );
    }
}
