<?php

declare(strict_types=1);

namespace App\Domain\Segment;

use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-type Metrics array{distance: float, elevationGain: ?float, elevationLoss: ?float, averageGradient: ?float, maxGradient: ?float}
 * @phpstan-type Override array{polyline: string, source: string, metrics?: Metrics, originalSegment?: array<string, mixed>, originalEfforts?: list<array<string, mixed>>}
 */
final readonly class LocalSegmentGeometry
{
    public const string OVERRIDES_KEY = 'g96.segmentGeometryOverrides.v1';

    public function __construct(private Connection $connection, private RenderCache $renderCache)
    {
    }

    public function forDisplay(Segment $segment): Segment
    {
        if ($segment->getPolyline() instanceof EncodedPolyline && !$segment->getType()->isCustom()) {
            return $segment;
        }
        $override = $this->overrides()[(string) $segment->getId()] ?? null;
        if (null !== $override) {
            $polyline = EncodedPolyline::fromString($override['polyline']);

            return 'gpx' === $override['source'] ? $segment->withUploadedPolyline($polyline)->withUploadedElevation($override['metrics']['elevationGain'] ?? null, $override['metrics']['elevationLoss'] ?? null) : $segment->withReconstructedPolyline($polyline);
        }
        if ($segment->getPolyline() instanceof EncodedPolyline) {
            return $segment;
        }
        $value = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => 'g96.localSegmentGeometry.v1']);
        if (false === $value) {
            return $segment;
        }
        $cache = Json::decode($value);
        $polyline = EncodedPolyline::fromOptionalString($this->cachedGeometry($cache, (string) $segment->getId()));

        return $polyline instanceof EncodedPolyline ? $segment->withReconstructedPolyline($polyline) : $segment;
    }

    /**
     * @return array<string, Override>
     */
    public function overrides(): array
    {
        $value = $this->connection->fetchOne('SELECT value FROM KeyValue WHERE key = :key', ['key' => self::OVERRIDES_KEY]);
        if (false === $value) {
            return [];
        }
        $decoded = Json::decode($value);
        if (!is_array($decoded)) {
            return [];
        }
        $overrides = [];
        foreach ($decoded as $segmentId => $override) {
            if (!is_string($segmentId)) {
                continue;
            }
            $normalized = $this->normalizeOverride($override);
            if (null !== $normalized) {
                $overrides[$segmentId] = $normalized;
            }
        }

        return $overrides;
    }

    /**
     * @param Metrics $metrics
     */
    public function recordUploaded(Segment $segment, array $metrics): void
    {
        $overrides = $this->overrides();
        if (isset($overrides[(string) $segment->getId()]) || !$segment->getPolyline() instanceof EncodedPolyline) {
            throw CouldNotProcessCommand::withReason('La traccia del nuovo segmento non è valida.');
        }
        $overrides[(string) $segment->getId()] = ['polyline' => (string) $segment->getPolyline(), 'source' => 'gpx', 'metrics' => $metrics];
        $this->persistOverrides($overrides, $segment);
    }

    /**
     * @param Metrics|null $metrics
     */
    public function saveMissing(Segment $segment, EncodedPolyline $polyline, string $source, ?array $metrics = null): void
    {
        if ($this->forDisplay($segment)->getPolyline() instanceof EncodedPolyline) {
            throw CouldNotProcessCommand::withReason('Il segmento ha già una traccia GPS. Non è stata sostituita.');
        }
        $overrides = $this->overrides();
        $overrides[(string) $segment->getId()] = ['polyline' => (string) $polyline, 'source' => $source];
        if (null !== $metrics) {
            $originalSegment = $this->connection->fetchAssociative('SELECT * FROM Segment WHERE segmentId = :id', ['id' => $segment->getId()]);
            $overrides[(string) $segment->getId()]['metrics'] = $metrics;
            $overrides[(string) $segment->getId()]['originalSegment'] = false === $originalSegment ? [] : $originalSegment;
            $overrides[(string) $segment->getId()]['originalEfforts'] = $this->connection->fetchAllAssociative('SELECT * FROM SegmentEffort WHERE segmentId = :id', ['id' => $segment->getId()]);
            $this->connection->executeStatement('UPDATE Segment SET distance = :distance, averageGradient = :average, maxGradient = :maximum WHERE segmentId = :id',
                ['distance' => (int) round($metrics['distance']), 'average' => $metrics['averageGradient'], 'maximum' => $metrics['maxGradient'], 'id' => $segment->getId()]);
        }
        $this->persistOverrides($overrides, $segment);
    }

    /**
     * @param array<string, Override> $overrides
     */
    private function persistOverrides(array $overrides, Segment $segment): void
    {
        $this->connection->executeStatement('INSERT INTO KeyValue (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            ['key' => self::OVERRIDES_KEY, 'value' => Json::encode($overrides)]);
        $this->renderCache->invalidateTags(RootCacheTag::ACTIVITIES, RootCacheTag::SEGMENTS, SegmentCacheTag::for($segment->getId()));
    }

    /**
     * @return Override|null
     */
    private function normalizeOverride(mixed $override): ?array
    {
        if (!is_array($override)) {
            return null;
        }
        $polyline = $override['polyline'] ?? null;
        $source = $override['source'] ?? null;
        if (!is_string($polyline) || !is_string($source)) {
            return null;
        }
        /** @var Override $normalized */
        $normalized = ['polyline' => $polyline, 'source' => $source];
        $metrics = $this->normalizeMetrics($override['metrics'] ?? null);
        if (null !== $metrics) {
            $normalized['metrics'] = $metrics;
        }
        $originalSegment = $override['originalSegment'] ?? null;
        if (is_array($originalSegment)) {
            $normalized['originalSegment'] = $this->stringKeyedArray($originalSegment);
        }
        $originalEfforts = $override['originalEfforts'] ?? null;
        if (is_array($originalEfforts)) {
            $efforts = [];
            foreach ($originalEfforts as $effort) {
                if (is_array($effort)) {
                    $efforts[] = $this->stringKeyedArray($effort);
                }
            }
            $normalized['originalEfforts'] = $efforts;
        }

        return $normalized;
    }

    /**
     * @return Metrics|null
     */
    private function normalizeMetrics(mixed $metrics): ?array
    {
        if (!is_array($metrics)) {
            return null;
        }
        $distance = $metrics['distance'] ?? null;
        if (!is_numeric($distance)) {
            return null;
        }

        return [
            'distance' => (float) $distance,
            'elevationGain' => $this->optionalFloat($metrics['elevationGain'] ?? null),
            'elevationLoss' => $this->optionalFloat($metrics['elevationLoss'] ?? null),
            'averageGradient' => $this->optionalFloat($metrics['averageGradient'] ?? null),
            'maxGradient' => $this->optionalFloat($metrics['maxGradient'] ?? null),
        ];
    }

    private function optionalFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function cachedGeometry(mixed $cache, string $segmentId): ?string
    {
        if (!is_array($cache)) {
            return null;
        }
        $geometries = $cache['geometries'] ?? null;
        if (!is_array($geometries)) {
            return null;
        }
        $geometry = $geometries[$segmentId] ?? null;
        if (!is_array($geometry)) {
            return null;
        }
        $polyline = $geometry['polyline'] ?? null;

        return is_string($polyline) ? $polyline : null;
    }
}
