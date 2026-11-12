<?php

declare(strict_types=1);

namespace App\Domain\Integration\OpenStreetMap;

use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Geography\BoundingBox;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

final readonly class LiveOverpassApi implements OverpassApi
{
    public function __construct(
        private Client $client,
        private SettingsRepository $settingsRepository,
    ) {
    }

    public function getHighwaysIn(BoundingBox $boundingBox): array
    {
        $endpoint = $this->settingsRepository->integrations()->getOverpassEndpoint();
        if (null === $endpoint) {
            throw new OverpassApiCallHasFailed('The OpenStreetMap integration is not enabled.');
        }

        $query = sprintf(
            '[out:json][timeout:%d];way["highway"](%s,%s,%s,%s);out tags geom;',
            60,
            $this->format($boundingBox->getMinLat()),
            $this->format($boundingBox->getMinLng()),
            $this->format($boundingBox->getMaxLat()),
            $this->format($boundingBox->getMaxLng()),
        );

        try {
            $response = $this->client->request('POST', $endpoint, [
                'body' => ['data' => $query],
                'headers' => ['User-Agent' => 'Dreeve'],
            ]);
            $decoded = Json::decode($response->getBody()->getContents());
        } catch (CorruptedData|ConnectException|RequestException) {
            throw new OverpassApiCallHasFailed('Could not query the Overpass API.');
        }

        if (!is_array($decoded) || !isset($decoded['elements']) || !is_array($decoded['elements'])) {
            throw new OverpassApiCallHasFailed('The Overpass API returned an unexpected response.');
        }

        $ways = [];
        foreach ($decoded['elements'] as $element) {
            if (!is_array($element) || 'way' !== ($element['type'] ?? null)) {
                continue;
            }

            $geometry = [];
            foreach (($element['geometry'] ?? []) as $point) {
                if (!is_array($point) || !isset($point['lat'], $point['lon'])) {
                    continue;
                }
                if (!is_numeric($point['lat']) || !is_numeric($point['lon'])) {
                    continue;
                }
                $geometry[] = [(float) $point['lat'], (float) $point['lon']];
            }

            if (count($geometry) < 2) {
                continue;
            }

            $tags = [];
            foreach (($element['tags'] ?? []) as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $tags[$key] = $value;
                }
            }

            $ways[] = new OpenStreetMapWay(
                id: (int) ($element['id'] ?? 0),
                geometry: $geometry,
                tags: $tags,
            );
        }

        return $ways;
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
