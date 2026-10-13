<?php

declare(strict_types=1);

namespace App\Domain\Segment\SegmentEffort\Matching;

use App\Infrastructure\ValueObject\Geography\EncodedPolyline;

final readonly class SegmentGeometryFromEffort
{
    /** @param array<string, mixed> $effort
     *  @param array<int, array{float, float}> $latLng
     *  @param array<int, int|float> $distance
     */
    public function reconstruct(array $effort, array $latLng, array $distance, float $length): ?EncodedPolyline
    {
        $start = $effort['start_index'] ?? null;
        $end = $effort['end_index'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end >= count($latLng)
            || !isset($distance[$start], $distance[$end]) || $length <= 0) {
            return null;
        }
        $covered = $distance[$end] - $distance[$start];
        // Reject missing, decimated or misaligned streams rather than guessing a route.
        if ($covered < $length * 0.65 || $covered > $length * 1.5) {
            return null;
        }
        $points = array_slice($latLng, $start, $end - $start + 1);
        foreach ($points as $point) {
            if (count($point) !== 2 || !is_numeric($point[0]) || !is_numeric($point[1])
                || !is_finite((float) $point[0]) || !is_finite((float) $point[1])
                || abs($point[0]) > 90 || abs($point[1]) > 180) {
                return null;
            }
        }
        return EncodedPolyline::fromCoordinates($points);
    }
}
