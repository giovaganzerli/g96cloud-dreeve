<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

/**
 * Calculates the elevation gain from a series of elevations.
 *
 * The elevation of a digital elevation model is noisy: a single sample can be off
 * by a few meters. The gain is therefore calculated on a smoothed series, where
 * the smoothing window is expressed in meters instead of samples. A window in
 * samples would depend on the density of the track, a track sampled every 25
 * meters would be smoothed over a completely different distance than a track
 * sampled every 100 meters.
 */
final readonly class ElevationGainCalculator
{
    /**
     * The minimum height difference (in meters) that counts as a climb or a descent.
     */
    public const float THRESHOLD_IN_METERS = 2.0;

    /**
     * The width (in meters) of the smoothing window.
     */
    public const float SMOOTHING_WINDOW_IN_METERS = 150.0;

    /**
     * @param list<float|null> $elevations
     * @param list<float>      $distances  the cumulative distance (in meters) for every elevation
     */
    public function calculate(array $elevations, array $distances, float $smoothingWindowInMeters = self::SMOOTHING_WINDOW_IN_METERS): float
    {
        $count = count($elevations);
        if (0 === $count) {
            return 0.0;
        }

        $gain = 0.0;
        $reference = $elevations[0];
        $start = 0;
        for ($end = 0; $end < $count; ++$end) {
            // Slide the window over the track, it always covers at most the smoothing width.
            while ($start < $end && ($distances[$end] - $distances[$start]) > $smoothingWindowInMeters) {
                ++$start;
            }

            $smoothed = 0.0;
            $samples = 0;
            for ($index = $start; $index <= $end; ++$index) {
                $smoothed += $elevations[$index];
                ++$samples;
            }
            $smoothed /= max(1, $samples);

            if ($smoothed - $reference >= self::THRESHOLD_IN_METERS) {
                $gain += $smoothed - $reference;
                $reference = $smoothed;
            } elseif ($reference - $smoothed >= self::THRESHOLD_IN_METERS) {
                $reference = $smoothed;
            }
        }

        return $gain;
    }
}
