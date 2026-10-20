<?php

declare(strict_types=1);

namespace App\Domain\Integration\Elevation\OpenMeteo;

use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Serialization\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

final readonly class LiveOpenMeteoElevation implements OpenMeteoElevation
{
    private const string BASE_URI = 'https://api.open-meteo.com/';

    public function __construct(
        private Client $client,
    ) {
    }

    public function getElevations(array $coordinates): array
    {
        if ([] === $coordinates) {
            return [];
        }

        $elevations = [];
        foreach (array_chunk($coordinates, self::MAX_COORDINATES_PER_REQUEST) as $batch) {
            $elevations = array_merge($elevations, $this->requestBatch($batch));
        }

        return $elevations;
    }

    /**
     * @param list<array{float, float}> $coordinates
     *
     * @return list<float|null>
     */
    private function requestBatch(array $coordinates): array
    {
        $options = [
            'base_uri' => self::BASE_URI,
            RequestOptions::QUERY => [
                'latitude' => implode(',', array_map(static fn (array $coordinate): string => (string) $coordinate[0], $coordinates)),
                'longitude' => implode(',', array_map(static fn (array $coordinate): string => (string) $coordinate[1], $coordinates)),
            ],
        ];

        try {
            $response = $this->client->request('GET', 'v1/elevation', $options);
            $data = Json::decode($response->getBody()->getContents());
        } catch (RequestException $exception) {
            if (429 === $exception->getResponse()?->getStatusCode()) {
                throw OpenMeteoElevationApiCallHasFailed::becauseTheRateLimitIsReached();
            }
            throw OpenMeteoElevationApiCallHasFailed::becauseTheServiceIsUnavailable($exception);
        } catch (CorruptedData|ConnectException $exception) {
            throw OpenMeteoElevationApiCallHasFailed::becauseTheServiceIsUnavailable($exception);
        }

        if (!is_array($data) || !isset($data['elevation']) || !is_array($data['elevation'])) {
            throw OpenMeteoElevationApiCallHasFailed::becauseTheResponseIsInvalid();
        }

        return array_map(
            static fn (mixed $elevation): ?float => is_numeric($elevation) ? (float) $elevation : null,
            array_values($data['elevation'])
        );
    }
}
