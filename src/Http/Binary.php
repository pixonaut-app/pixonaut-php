<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

/**
 * A successful answer that is a file rather than JSON: an image, a PDF, a
 * ZIP.
 */
final class Binary
{
    /**
     * @param string      $contentType The answer's `Content-Type`, e.g. `image/png`; empty when it had none.
     * @param string      $bytes       The file.
     * @param string|null $requestId   The answer's `X-Request-Id`.
     */
    public function __construct(
        public readonly string $contentType,
        public readonly string $bytes,
        public readonly ?string $requestId = null,
    ) {}
}
