<?php

declare(strict_types=1);

namespace App\Domain\Integration\Immich;

use App\Domain\Settings\SettingsRepository;
use App\Infrastructure\Exception\CorruptedData;
use App\Infrastructure\Serialization\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

final readonly class LiveImmichApi implements ImmichApi
{
    public function __construct(
        private Client $client,
        private SettingsRepository $settingsRepository,
    ) {
    }

    public function getAlbums(): array
    {
        $decoded = $this->request('GET', 'api/albums');

        $albums = [];
        foreach ($decoded as $album) {
            if (!is_array($album) || !isset($album['id'])) {
                continue;
            }
            $albums[] = new ImmichAlbum(
                id: (string) $album['id'],
                name: (string) ($album['albumName'] ?? $album['name'] ?? ''),
                description: isset($album['description']) && is_string($album['description']) ? $album['description'] : null,
                assetCount: (int) ($album['assetCount'] ?? 0),
            );
        }

        return $albums;
    }

    public function getAlbumAssets(string $albumId): array
    {
        // Newer Immich versions moved the assets out of the album resource.
        try {
            $decoded = $this->request('GET', 'api/assets', ['query' => ['albumId' => $albumId, 'size' => 1000]]);
            if (isset($decoded['assets']) && is_array($decoded['assets'])) {
                return $this->hydrateAssets($decoded['assets']);
            }
        } catch (ImmichApiCallHasFailed) {
            // Fall through to the legacy endpoint.
        }

        $decoded = $this->request('GET', 'api/albums/'.rawurlencode($albumId));

        return $this->hydrateAssets(is_array($decoded['assets'] ?? null) ? $decoded['assets'] : []);
    }

    public function getAssetPreview(string $assetId): ?ImmichImage
    {
        $url = $this->baseUrl();
        $apiKey = $this->apiKey();

        try {
            $response = $this->client->request('GET', sprintf('%s/api/assets/%s/thumbnail', $url, rawurlencode($assetId)), [
                RequestOptions::QUERY => ['size' => 'preview'],
                RequestOptions::HEADERS => ['x-api-key' => $apiKey],
            ]);
        } catch (ConnectException|RequestException) {
            return null;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ('' === $contentType) {
            $contentType = 'image/jpeg';
        }

        return new ImmichImage(
            content: (string) $response->getBody(),
            contentType: $contentType,
        );
    }

    /**
     * @param array<mixed> $assets
     *
     * @return list<ImmichAsset>
     */
    private function hydrateAssets(array $assets): array
    {
        $hydrated = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['id'])) {
                continue;
            }
            $hydrated[] = new ImmichAsset(
                id: (string) $asset['id'],
                type: (string) ($asset['type'] ?? 'IMAGE'),
                originalPath: isset($asset['originalPath']) && is_string($asset['originalPath']) ? $asset['originalPath'] : null,
            );
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl();
        $apiKey = $this->apiKey();

        $options[RequestOptions::HEADERS] = array_merge(
            $options[RequestOptions::HEADERS] ?? [],
            ['x-api-key' => $apiKey, 'Accept' => 'application/json'],
        );

        try {
            $response = $this->client->request($method, sprintf('%s/%s', $url, ltrim($path, '/')), $options);
            $content = $response->getBody()->getContents();
        } catch (ConnectException|RequestException) {
            throw new ImmichApiCallHasFailed('Could not reach the Immich API.');
        }

        try {
            $decoded = Json::decode($content);
        } catch (CorruptedData) {
            throw new ImmichApiCallHasFailed('The Immich API returned an invalid response.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function baseUrl(): string
    {
        $url = $this->settingsRepository->integrations()->getImmichUrl();
        if (null === $url) {
            throw new ImmichApiCallHasFailed('The Immich integration is not configured.');
        }

        return rtrim($url, '/');
    }

    private function apiKey(): string
    {
        $apiKey = $this->settingsRepository->integrations()->getImmichApiKey();
        if (null === $apiKey) {
            throw new ImmichApiCallHasFailed('The Immich integration is not configured.');
        }

        return $apiKey;
    }
}
