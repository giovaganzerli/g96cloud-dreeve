<?php

declare(strict_types=1);

namespace App\Domain\Segment;

use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Doctrine\DBAL\Connection;

final readonly class LocalSegmentGeometry
{
    public function __construct(private Connection $connection)
    {
    }

    public function forDisplay(Segment $segment): Segment
    {
        if (null !== $segment->getPolyline() || $segment->getType()->isCustom()) {
            return $segment;
        }
        $value = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => 'g96.localSegmentGeometry.v1']);
        if (false === $value) {
            return $segment;
        }
        $cache = Json::decode($value);
        $polyline = EncodedPolyline::fromOptionalString($cache['geometries'][(string) $segment->getId()]['polyline'] ?? null);
        return null !== $polyline ? $segment->withReconstructedPolyline($polyline) : $segment;
    }
}
