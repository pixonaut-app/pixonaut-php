<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

/**
 * One published API release's files, byte for byte as the CDN serves them.
 */
final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $spec,
        public readonly string $vectors,
    ) {}
}
