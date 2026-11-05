<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use DateTimeImmutable;

/**
 * Pixonaut's notice that something a request used is deprecated: the
 * `Deprecation` header (`@` and Unix seconds) and its `Sunset` (an HTTP date).
 */
final class Deprecation
{
    /** Unix seconds up to the year 33658, far past any sunset and well inside a 64-bit date. */
    private const MAX_TIMESTAMP_DIGITS = 12;

    /**
     * @param string                 $operation  Operation id.
     * @param string                 $header     The `Deprecation` header as sent.
     * @param DateTimeImmutable|null $deprecated When it was deprecated, null when the header is not `@<seconds>`.
     * @param string|null            $sunsetHeader The `Sunset` header as sent, null when none was.
     * @param DateTimeImmutable|null $sunset     When it stops working, null when unknown.
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $header,
        public readonly ?DateTimeImmutable $deprecated,
        public readonly ?string $sunsetHeader,
        public readonly ?DateTimeImmutable $sunset,
    ) {}

    public static function fromHeaders(string $operation, string $deprecation, ?string $sunset): self
    {
        $deprecated = null;
        // A longer number is out of the range a date holds: kept as the raw header only.
        if (preg_match('/^@(\d{1,' . self::MAX_TIMESTAMP_DIGITS . '})\z/', trim($deprecation), $match) === 1) {
            $deprecated = new DateTimeImmutable('@' . $match[1]);
        }

        return new self($operation, $deprecation, $deprecated, $sunset, HttpDate::parse($sunset));
    }
}
