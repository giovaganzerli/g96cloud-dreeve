<?php

namespace App\Tests\Infrastructure\Http;

use App\Application\AppVersion;
use App\Application\BuildIdentity;
use App\Infrastructure\Config\DevMode;
use App\Infrastructure\Http\AssetVersioningStrategy;
use App\Tests\Infrastructure\ValueObject\Identifier\FakeUuidFactory;
use PHPUnit\Framework\TestCase;

class AssetVersioningStrategyTest extends TestCase
{
    public function testApplyVersion(): void
    {
        $strategy = new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), BuildIdentity::fromString(''));
        $version = AppVersion::getSemanticVersion();

        $this->assertEquals(
            '/test/file?'.$version,
            $strategy->applyVersion('/test/file')
        );

        $this->assertEquals(
            'test/file?'.$version,
            $strategy->applyVersion('test/file')
        );
    }

    public function testItShouldBeStableAcrossInstances(): void
    {
        $this->assertEquals(
            new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), BuildIdentity::fromString(''))->applyVersion('/test/file'),
            new AssetVersioningStrategy(DevMode::fromString('0'), new FakeUuidFactory(), BuildIdentity::fromString(''))->applyVersion('/test/file')
        );
    }

    public function testItUsesTheCommitAsVersion(): void
    {
        $strategy = new AssetVersioningStrategy(
            DevMode::fromString('0'),
            new FakeUuidFactory(),
            BuildIdentity::fromString('147d42b11340c2ff1b51c89bdf57295560cf2f')
        );

        $this->assertEquals('/test/file?147d42b', $strategy->applyVersion('/test/file'));
    }

    public function testItShouldHandOutAFreshVersionInDevMode(): void
    {
        $this->assertEquals(
            '/test/file?'.new FakeUuidFactory()->random(),
            new AssetVersioningStrategy(DevMode::fromString('1'), new FakeUuidFactory(), BuildIdentity::fromString(''))->applyVersion('/test/file')
        );
    }
}
