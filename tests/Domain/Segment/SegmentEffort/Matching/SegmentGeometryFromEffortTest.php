<?php

declare(strict_types=1);

namespace App\Tests\Domain\Segment\SegmentEffort\Matching;

use App\Domain\Segment\SegmentEffort\Matching\SegmentGeometryFromEffort;
use PHPUnit\Framework\TestCase;

final class SegmentGeometryFromEffortTest extends TestCase
{
    public function testReconstructsOnlyTheRecordedSegmentSlice(): void
    {
        $points = [[45.0, 9.0], [45.001, 9.001], [45.002, 9.002], [45.003, 9.003]];
        $result = new SegmentGeometryFromEffort()->reconstruct(['start_index' => 1, 'end_index' => 2], $points, [0, 100, 200, 300], 100);
        self::assertNotNull($result);
        self::assertEquals([$points[1], $points[2]], $result->decodeAndPairLatLng());
    }

    public function testRejectsMissingAndMisalignedStreams(): void
    {
        $builder = new SegmentGeometryFromEffort();
        $points = [[45.0, 9.0], [45.001, 9.001]];
        self::assertNull($builder->reconstruct([], $points, [0, 100], 100));
        self::assertNull($builder->reconstruct(['start_index' => 0, 'end_index' => 2], $points, [0, 100], 100));
        self::assertNull($builder->reconstruct(['start_index' => 0, 'end_index' => 1], $points, [0, 5], 100));
        self::assertNull($builder->reconstruct(['start_index' => 0, 'end_index' => 1], [[95, 9], [45, 9]], [0, 100], 100));
    }
}
