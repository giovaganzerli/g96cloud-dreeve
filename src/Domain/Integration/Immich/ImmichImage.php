<?php

declare(strict_types=1);

namespace App\Domain\Integration\Immich;

final readonly class ImmichImage
{
    public function __construct(
        private string $content,
        private string $contentType,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }
}
