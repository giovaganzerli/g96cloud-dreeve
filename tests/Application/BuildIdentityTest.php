<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\AppVersion;
use App\Application\BuildIdentity;
use PHPUnit\Framework\TestCase;

class BuildIdentityTest extends TestCase
{
    public function testItUsesTheCommitAsVersion(): void
    {
        $this->assertSame(
            '147d42b',
            BuildIdentity::fromString('147d42b11340c2ff1b51c89bdf57295560cf2f')->getVersion()
        );
    }

    public function testItFallsBackToTheAppVersionWithoutACommit(): void
    {
        $this->assertSame(
            AppVersion::getSemanticVersion(),
            BuildIdentity::fromString('')->getVersion()
        );
    }

    public function testItIgnoresSomethingThatIsNotACommit(): void
    {
        $this->assertSame(
            AppVersion::getSemanticVersion(),
            BuildIdentity::fromString('not-a-commit')->getVersion()
        );
    }
}
