<?php

declare(strict_types=1);

namespace App\Domain\Segment\Geometry;

use App\Domain\Import\FileParser\Xml;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use App\Infrastructure\ValueObject\Geography\GeoMath;

final readonly class GpxSegmentTrackParser
{
    public const int MAX_BYTES = 5 * 1024 * 1024;
    private const int MAX_POINTS = 25000;

    public function parse(string $contents, float $expectedLength = 0): EncodedPolyline
    {
        if (strlen($contents) > self::MAX_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
            throw CouldNotProcessCommand::withReason('Il GPX supera 5 MB o contiene dichiarazioni XML non supportate.');
        }
        $xml = Xml::load($contents);
        if (!$xml instanceof \SimpleXMLElement || 'gpx' !== $xml->getName()) {
            throw CouldNotProcessCommand::withReason('Il file non è un GPX valido.');
        }
        $tracks = $xml->xpath('./*[local-name()="trk"]/*[local-name()="trkseg"] | ./*[local-name()="rte"]') ?: [];
        if (1 !== count($tracks)) {
            throw CouldNotProcessCommand::withReason('Carica un GPX con un solo percorso continuo del segmento.');
        }
        $points = $tracks[0]->xpath('./*[local-name()="trkpt" or local-name()="rtept"]') ?: [];
        if (count($points) < 2 || count($points) > self::MAX_POINTS) {
            throw CouldNotProcessCommand::withReason('Il GPX deve contenere da 2 a 25.000 punti GPS.');
        }
        $coordinates = [];
        $length = 0.0;
        foreach ($points as $point) {
            $lat = (string) $point['lat'];
            $lng = (string) $point['lon'];
            if (!is_numeric($lat) || !is_numeric($lng) || !is_finite((float) $lat) || !is_finite((float) $lng)
                || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                throw CouldNotProcessCommand::withReason('Il GPX contiene coordinate GPS non valide.');
            }
            $coordinate = [(float) $lat, (float) $lng];
            if ([] !== $coordinates) {
                [$previousLat, $previousLng] = array_last($coordinates);
                $length += GeoMath::haversineDistance($previousLat, $previousLng, ...$coordinate);
            }
            $coordinates[] = $coordinate;
        }
        if ($length < 1 || ($expectedLength > 0 && ($length < $expectedLength * 0.65 || $length > $expectedLength * 1.5))) {
            throw CouldNotProcessCommand::withReason('La lunghezza della traccia non corrisponde al segmento. Carica il GPX del solo segmento, non dell’intera attività.');
        }

        return EncodedPolyline::fromCoordinates($coordinates);
    }

    /**
     * Validate the track and calculate metrics from GPS coordinates and optional elevations.
     *
     * @return array{distance: float, elevationGain: ?float, elevationLoss: ?float, averageGradient: ?float, maxGradient: float}
     */
    public function metrics(string $contents): array
    {
        $this->parse($contents);
        $xml = Xml::load($contents);
        if (!$xml instanceof \SimpleXMLElement) {
            throw CouldNotProcessCommand::withReason('Il file non è un GPX valido.');
        }
        $points = $xml->xpath('./*[local-name()="trk"]/*[local-name()="trkseg"]/*[local-name()="trkpt"] | ./*[local-name()="rte"]/*[local-name()="rtept"]') ?: [];
        $distance = [0.0];
        $altitude = [];
        $firstAltitude = null;
        $lastAltitude = null;
        $cumulative = 0.0;
        $previous = null;
        foreach ($points as $point) {
            $coordinate = [(float) $point['lat'], (float) $point['lon']];
            if (null !== $previous) {
                $cumulative += GeoMath::haversineDistance($previous[0], $previous[1], $coordinate[0], $coordinate[1]);
                $distance[] = $cumulative;
            }
            $previous = $coordinate;
            $ele = $point->xpath('./*[local-name()="ele"]') ?: [];
            if ([] !== $ele) {
                $value = (string) $ele[0];
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw CouldNotProcessCommand::withReason('Il GPX contiene quote non valide.');
                }
                $altitude[] = (float) $value;
                $firstAltitude ??= (float) $value;
                $lastAltitude = (float) $value;
            }
        }
        $length = $cumulative;
        $gain = $loss = $average = null;
        $maximum = 0.0;
        if (count($altitude) === count($points)) {
            $gain = $loss = 0.0;
            $counter = count($altitude);
            for ($i = 1; $i < $counter; ++$i) {
                $delta = $altitude[$i] - $altitude[$i - 1];
                $gain += max(0.0, $delta);
                $loss += max(0.0, -$delta);
            }
            if (null !== $firstAltitude && null !== $lastAltitude) {
                $average = round(($lastAltitude - $firstAltitude) / $length * 100, 1);
            }
            $maximum = $average ?? 0.0;
            // Use the same 50 m gradient window as AddCustomSegmentCommandHandler.
            $end = 0;
            foreach ($distance as $i => $value) {
                while ($end < count($distance) && $distance[$end] - $value < 50) {
                    ++$end;
                }
                if ($end >= count($distance)) {
                    break;
                }
                $maximum = max($maximum, round(($altitude[$end] - $altitude[$i]) / ($distance[$end] - $value) * 100, 1));
            }
        }

        return ['distance' => $length, 'elevationGain' => $gain, 'elevationLoss' => $loss,
            'averageGradient' => $average, 'maxGradient' => $maximum];
    }
}
