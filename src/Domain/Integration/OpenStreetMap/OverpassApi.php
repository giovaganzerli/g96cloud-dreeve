<?php

declare(strict_types=1);

namespace App\Domain\Integration\OpenStreetMap;

use App\Infrastructure\ValueObject\Geography\BoundingBox;

interface OverpassApi
{
    /**
     * Returns all the highway ways that are located inside the given bounding box.
     *
     * @return list<OpenStreetMapWay>
     */
    public function getHighwaysIn(BoundingBox $boundingBox): array;
}
