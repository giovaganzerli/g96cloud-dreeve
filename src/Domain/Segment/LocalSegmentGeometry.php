<?php

declare(strict_types=1);

namespace App\Domain\Segment;

use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Doctrine\DBAL\Connection;

final readonly class LocalSegmentGeometry
{
    public const string OVERRIDES_KEY = 'g96.segmentGeometryOverrides.v1';

    public function __construct(private Connection $connection, private RenderCache $renderCache)
    {
    }

    public function forDisplay(Segment $segment): Segment
    {
        if (null !== $segment->getPolyline()) {
            return $segment;
        }
        $override = $this->overrides()[(string) $segment->getId()] ?? null;
        if (null !== $override) {
            $polyline = EncodedPolyline::fromString($override['polyline']);
            return 'gpx' === $override['source'] ? $segment->withUploadedPolyline($polyline) : $segment->withReconstructedPolyline($polyline);
        }
        $value = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => 'g96.localSegmentGeometry.v1']);
        if (false === $value) {
            return $segment;
        }
        $cache = Json::decode($value);
        $polyline = EncodedPolyline::fromOptionalString($cache['geometries'][(string) $segment->getId()]['polyline'] ?? null);
        return null !== $polyline ? $segment->withReconstructedPolyline($polyline) : $segment;
    }

    public function overrides(): array
    {
        $value = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => self::OVERRIDES_KEY]);
        return false !== $value ? Json::decode($value) : [];
    }

    public function saveMissing(Segment $segment, EncodedPolyline $polyline, string $source): void
    {
        if (null !== $this->forDisplay($segment)->getPolyline()) {
            throw CouldNotProcessCommand::withReason('Il segmento ha già una traccia GPS. Non è stata sostituita.');
        }
        $overrides = $this->overrides();
        $overrides[(string) $segment->getId()] = ['polyline' => (string) $polyline, 'source' => $source];
        $this->connection->executeStatement('INSERT INTO KeyValue (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['key' => self::OVERRIDES_KEY, 'value' => Json::encode($overrides)]);
        $this->renderCache->invalidateTags(RootCacheTag::SEGMENTS, SegmentCacheTag::for($segment->getId()));
    }
}
