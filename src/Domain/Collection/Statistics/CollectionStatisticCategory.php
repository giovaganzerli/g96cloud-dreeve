<?php

declare(strict_types=1);

namespace App\Domain\Collection\Statistics;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CollectionStatisticCategory: string implements TranslatableInterface
{
    case TIME = 'time';
    case DISTANCE = 'distance';
    case ELEVATION = 'elevation';
    case SPEED = 'speed';
    case STAGES = 'stages';
    case WEATHER = 'weather';
    case GEOGRAPHY = 'geography';
    case SURFACE = 'surface';
    case OTHER = 'other';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::TIME => $translator->trans('Time', locale: $locale),
            self::DISTANCE => $translator->trans('Distance', locale: $locale),
            self::ELEVATION => $translator->trans('Elevation', locale: $locale),
            self::SPEED => $translator->trans('Speed', locale: $locale),
            self::STAGES => $translator->trans('Stages', locale: $locale),
            self::WEATHER => $translator->trans('Weather', locale: $locale),
            self::GEOGRAPHY => $translator->trans('Geography', locale: $locale),
            self::SURFACE => $translator->trans('Surfaces', locale: $locale),
            self::OTHER => $translator->trans('Other', locale: $locale),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::TIME => 'time',
            self::DISTANCE => 'distance',
            self::ELEVATION => 'elevation',
            self::SPEED => 'speed',
            self::STAGES => 'route',
            self::WEATHER => 'sun',
            self::GEOGRAPHY => 'globe',
            self::SURFACE => 'ruler',
            self::OTHER => 'hashtag',
        };
    }
}
