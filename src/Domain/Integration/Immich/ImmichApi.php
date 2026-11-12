<?php

declare(strict_types=1);

namespace App\Domain\Integration\Immich;

interface ImmichApi
{
    /**
     * @return list<ImmichAlbum>
     */
    public function getAlbums(): array;

    /**
     * @return list<ImmichAsset>
     */
    public function getAlbumAssets(string $albumId): array;

    public function getAssetPreview(string $assetId): ?ImmichImage;
}
