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
        if (null === $xml || 'gpx' !== $xml->getName()) {
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
                [$previousLat, $previousLng] = $coordinates[array_key_last($coordinates)];
                $length += GeoMath::haversineDistance($previousLat, $previousLng, ...$coordinate);
            }
            $coordinates[] = $coordinate;
        }
        if ($length < 1 || ($expectedLength > 0 && ($length < $expectedLength * 0.65 || $length > $expectedLength * 1.5))) {
            throw CouldNotProcessCommand::withReason('La lunghezza della traccia non corrisponde al segmento. Carica il GPX del solo segmento, non dell’intera attività.');
        }
        return EncodedPolyline::fromCoordinates($coordinates);
    }
}
