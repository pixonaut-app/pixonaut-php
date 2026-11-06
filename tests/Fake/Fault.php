<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

/**
 * Something going wrong on the next request the fake answers.
 */
final class Fault
{
    /** An error answer with a status and a code. */
    public const STATUS = 'status';

    /** The usual answer, with a `Deprecation` header and maybe a `Sunset`. */
    public const DEPRECATED = 'deprecated';

    /** No answer: the request never reached the fake. */
    public const TRANSPORT = 'transport';

    /** The request is answered, then the answer is lost on the way back. */
    public const LOST_ANSWER = 'lost_answer';

    /** A success status with a body that is not JSON, as a proxy's error page would be. */
    public const NOT_JSON = 'not_json';

    private const SERVICE_UNAVAILABLE = 503;

    private const TOO_MANY_REQUESTS = 429;

    private function __construct(
        public readonly string $kind,
        public readonly int $status = 0,
        public readonly ?string $code = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $deprecation = null,
        public readonly ?string $sunset = null,
    ) {}

    /**
     * A server-side failure, `503` by default.
     */
    public static function serverError(int $status = self::SERVICE_UNAVAILABLE, string $code = 'service_unavailable', ?int $retryAfter = null): self
    {
        return new self(self::STATUS, $status, $code, $retryAfter);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::STATUS, self::TOO_MANY_REQUESTS, 'rate_limited', $retryAfter);
    }

    /**
     * @param string      $deprecation The `Deprecation` header, e.g. `@1790000000`.
     * @param string|null $sunset      The `Sunset` header, an HTTP date.
     */
    public static function deprecated(string $deprecation, ?string $sunset = null): self
    {
        return new self(self::DEPRECATED, deprecation: $deprecation, sunset: $sunset);
    }

    public static function transportFailure(): self
    {
        return new self(self::TRANSPORT);
    }

    public static function lostAnswer(): self
    {
        return new self(self::LOST_ANSWER);
    }

    public static function notJson(): self
    {
        return new self(self::NOT_JSON);
    }
}
