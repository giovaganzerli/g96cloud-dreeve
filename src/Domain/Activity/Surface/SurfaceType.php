<?php

declare(strict_types=1);

namespace App\Domain\Activity\Surface;

enum SurfaceType: string
{
    case PAVED = 'paved';
    case UNPAVED = 'unpaved';
    case UNKNOWN = 'unknown';
}
