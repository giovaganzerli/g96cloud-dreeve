<?php

declare(strict_types=1);

namespace App\Domain\Segment\SegmentEffort\Matching;

use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

final readonly class SegmentGeometryFromEffort
{
    /** @param array<string, mixed> $effort
     * @param array<int, mixed> $latLng
     * @param array<int, mixed> $distance
     */
    public function reconstruct(array $effort, array $latLng, array $distance, float $length): ?EncodedPolyline
    {
        $start = $effort['start_index'] ?? null;
        $end = $effort['end_index'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end >= count($latLng)
            || !isset($distance[$start], $distance[$end]) || $length <= 0) {
            return null;
        }
        $startDistance = $distance[$start];
        $endDistance = $distance[$end];
        if (!is_numeric($startDistance) || !is_numeric($endDistance)) {
            return null;
        }
        $covered = (float) $endDistance - (float) $startDistance;
        // Reject missing, decimated or misaligned streams rather than guessing a route.
        if ($covered < $length * 0.65 || $covered > $length * 1.5) {
            return null;
        }
        $points = [];
        foreach (array_slice($latLng, $start, $end - $start + 1) as $point) {
            if (!is_array($point) || 2 !== count($point)) {
                return null;
            }
            $values = array_values($point);
            if (!is_numeric($values[0]) || !is_numeric($values[1])) {
                return null;
            }
            $latitude = (float) $values[0];
            $longitude = (float) $values[1];
            if (!is_finite($latitude) || !is_finite($longitude)
                || abs($latitude) > 90 || abs($longitude) > 180) {
                return null;
            }
            $points[] = [$latitude, $longitude];
        }

        return EncodedPolyline::fromCoordinates($points);
    }
}
