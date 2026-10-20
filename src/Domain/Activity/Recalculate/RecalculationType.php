<?php

declare(strict_types=1);

namespace App\Domain\Activity\Recalculate;

enum RecalculationType: string
{
    case ELEVATION = 'elevation';
    case TIME = 'time';
    case DISTANCE = 'distance';

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<self>
     */
    public static function fromPayload(array $payload): array
    {
        $types = [];
        foreach ($payload as $type) {
            if (is_string($type) && self::tryFrom($type) instanceof self) {
                $types[] = self::from($type);
            }
        }

        return array_values(array_unique($types, SORT_REGULAR));
    }
}
