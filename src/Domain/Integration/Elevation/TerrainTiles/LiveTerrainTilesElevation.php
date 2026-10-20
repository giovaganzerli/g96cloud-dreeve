<?php

declare(strict_types=1);

namespace App\Domain\Integration\Elevation\TerrainTiles;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Reads the elevation from the public terrain tiles.
 *
 * The tiles are raster images where the elevation is encoded in the RGB channels
 * (the "terrarium" encoding). The tiles cover the whole world and blend several
 * elevation datasets, which makes them a good second source next to a
 * satellite elevation model.
 */
final readonly class LiveTerrainTilesElevation implements TerrainTilesElevation
{
    private const string BASE_URI = 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/';

    /**
     * The zoom level that matches the ~30 meter resolution of the elevation data.
     */
    private const int ZOOM_LEVEL = 12;

    private const int TILE_SIZE = 256;

    public function __construct(
        private Client $client,
    ) {
    }

    public function getElevations(array $coordinates): array
    {
        if ([] === $coordinates) {
            return [];
        }

        $tiles = [];
        $elevations = [];
        foreach ($coordinates as $coordinate) {
            $latitude = $coordinate[0];
            $longitude = $coordinate[1];

            [$tileX, $tileY] = $this->tileFor($latitude, $longitude);
            $key = $tileX.'/'.$tileY;
            $tiles[$key] ??= $this->downloadTile($tileX, $tileY);

            $elevations[] = $this->elevationAt($tiles[$key], $latitude, $longitude, $tileX, $tileY);
        }

        return $elevations;
    }

    /**
     * @return array{int, int}
     */
    private function tileFor(float $latitude, float $longitude): array
    {
        $zoom = self::ZOOM_LEVEL;
        $tileX = (int) floor((($longitude + 180) / 360) * (2 ** $zoom));

        $latitudeRadians = deg2rad($latitude);
        $tileY = (int) floor((1 - log(tan($latitudeRadians) + 1 / cos($latitudeRadians)) / M_PI) / 2 * (2 ** $zoom));

        return [$tileX, $tileY];
    }

    private function downloadTile(int $tileX, int $tileY): \GdImage
    {
        try {
            $response = $this->client->request('GET', sprintf('%d/%d/%d.png', self::ZOOM_LEVEL, $tileX, $tileY), [
                'base_uri' => self::BASE_URI,
            ]);
        } catch (ConnectException|RequestException $exception) {
            throw TerrainTilesApiCallHasFailed::becauseTheServiceIsUnavailable($exception);
        }

        $image = @imagecreatefromstring($response->getBody()->getContents());
        if (false === $image) {
            throw TerrainTilesApiCallHasFailed::becauseTheServiceIsUnavailable();
        }

        return $image;
    }

    private function elevationAt(\GdImage $tile, float $latitude, float $longitude, int $tileX, int $tileY): float
    {
        $zoom = self::ZOOM_LEVEL;
        $worldX = (($longitude + 180) / 360) * (2 ** $zoom) * self::TILE_SIZE;

        $latitudeRadians = deg2rad($latitude);
        $worldY = (1 - log(tan($latitudeRadians) + 1 / cos($latitudeRadians)) / M_PI) / 2 * (2 ** $zoom) * self::TILE_SIZE;

        $pixelX = (int) floor($worldX - $tileX * self::TILE_SIZE);
        $pixelY = (int) floor($worldY - $tileY * self::TILE_SIZE);
        $pixelX = max(0, min(self::TILE_SIZE - 1, $pixelX));
        $pixelY = max(0, min(self::TILE_SIZE - 1, $pixelY));

        $color = imagecolorat($tile, $pixelX, $pixelY);
        $red = ($color >> 16) & 0xFF;
        $green = ($color >> 8) & 0xFF;
        $blue = $color & 0xFF;

        // The terrarium encoding: elevation = (R * 256 + G + B / 256) - 32768.
        return ($red * 256 + $green + $blue / 256) - 32768;
    }
}
