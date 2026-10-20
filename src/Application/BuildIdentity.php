<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Identifies the build that is running.
 *
 * The image bakes in the commit it was built from. Deploying a new commit changes the
 * identity, which invalidates the rendered pages and busts the browser cache of the
 * assets, without having to bump the app version for every deployment.
 */
final readonly class BuildIdentity
{
    private function __construct(
        private ?string $commit,
    ) {
    }

    public static function fromString(string $commit): self
    {
        $commit = strtolower(trim($commit));

        if (1 !== preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            return new self(null);
        }

        return new self(substr($commit, 0, 7));
    }

    /**
     * The version of the running build: the commit it was built from, or the app
     * version when the commit is unknown.
     */
    public function getVersion(): string
    {
        return $this->commit ?? AppVersion::getSemanticVersion();
    }
}
