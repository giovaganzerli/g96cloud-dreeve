<?php

declare(strict_types=1);

namespace App\Infrastructure\ValueObject\Geography;

final readonly class GeoDistance
{
    private const float METERS_PER_DEGREE_LATITUDE = 110_574.0;
    private const float METERS_PER_DEGREE_LONGITUDE = 111_320.0;

    /**
     * The distance between two coordinates, in meters. It uses an equirectangular
     * approximation, which is accurate enough for the short distances we deal with.
     */
    public static function inMeter(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB,
    ): float {
        $latitudeDelta = $latitudeB - $latitudeA;
        $longitudeDelta = ($longitudeB - $longitudeA) * cos(deg2rad(($latitudeA + $latitudeB) / 2));

        return sqrt(
            ($latitudeDelta * self::METERS_PER_DEGREE_LATITUDE) ** 2
            + ($longitudeDelta * self::METERS_PER_DEGREE_LONGITUDE) ** 2
        );
    }

    /**
     * The distance between a coordinate and a line segment, in meters.
     */
    public static function inMeterToSegment(
        float $latitude,
        float $longitude,
        float $latitudeStart,
        float $longitudeStart,
        float $latitudeEnd,
        float $longitudeEnd,
    ): float {
        $segmentLength = self::inMeter(
            $latitudeStart,
            $longitudeStart,
            $latitudeEnd,
            $longitudeEnd,
        );
        if (0.0 === $segmentLength) {
            return self::inMeter($latitude, $longitude, $latitudeStart, $longitudeStart);
        }

        $latitudeDelta = $latitudeEnd - $latitudeStart;
        $longitudeDelta = ($longitudeEnd - $longitudeStart) * cos(deg2rad(($latitudeStart + $latitudeEnd) / 2));
        $latitudeToStart = $latitude - $latitudeStart;
        $longitudeToStart = ($longitude - $longitudeStart) * cos(deg2rad(($latitudeStart + $latitudeEnd) / 2));

        $projection = (($latitudeDelta * $latitudeToStart) + ($longitudeDelta * $longitudeToStart))
            / (($latitudeDelta ** 2) + ($longitudeDelta ** 2));
        $projection = max(0.0, min(1.0, $projection));

        $closestLatitude = $latitudeStart + $projection * $latitudeDelta;
        $closestLongitude = $longitudeStart + $projection * ($longitudeEnd - $longitudeStart);

        return self::inMeter($latitude, $longitude, $closestLatitude, $closestLongitude);
    }
}
