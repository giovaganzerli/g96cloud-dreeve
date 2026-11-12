<?php

declare(strict_types=1);

namespace App\Domain\Collection\Gallery;

use App\Domain\Collection\Collection;
use App\Domain\Integration\Immich\ImmichApi;
use App\Domain\Integration\Immich\ImmichAsset;
use App\Domain\Settings\SettingsRepository;

final readonly class CollectionGallery
{
    private const int DEFAULT_NUMBER_OF_PHOTOS = 10;

    public function __construct(
        private ImmichApi $immichApi,
        private SettingsRepository $settingsRepository,
    ) {
    }

    /**
     * Returns a random selection of photos from the linked Immich album. The selection
     * changes every time it is called.
     *
     * @return list<ImmichAsset>
     */
    public function randomPhotos(Collection $collection, ?int $limit = null): array
    {
        $albumId = $collection->getImmichAlbumId();
        if (null === $albumId) {
            return [];
        }

        $limit ??= self::DEFAULT_NUMBER_OF_PHOTOS;

        try {
            $assets = $this->immichApi->getAlbumAssets($albumId);
        } catch (\Throwable) {
            return [];
        }

        $images = array_values(array_filter(
            $assets,
            static fn (ImmichAsset $asset): bool => $asset->isImage()
        ));

        if ([] === $images) {
            return [];
        }

        if (count($images) <= $limit) {
            return $images;
        }

        $selected = [];
        $pool = $images;
        while (count($selected) < $limit && [] !== $pool) {
            $index = random_int(0, count($pool) - 1);
            $selected[] = $pool[$index];
            unset($pool[$index]);
            $pool = array_values($pool);
        }

        return $selected;
    }

    /**
     * The URL of the linked Immich album, so the athlete can browse the full album.
     */
    public function albumUrl(Collection $collection): ?string
    {
        $albumId = $collection->getImmichAlbumId();
        if (null === $albumId) {
            return null;
        }

        $baseUrl = $this->settingsRepository->integrations()->getImmichUrl();
        if (null === $baseUrl) {
            return null;
        }

        return sprintf('%s/albums/%s', rtrim($baseUrl, '/'), rawurlencode($albumId));
    }
}
