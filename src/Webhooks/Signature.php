<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Webhooks;

/**
 * Pixonaut signs each delivery attempt with the endpoint's secret: the
 * `x-pixonaut-signature` header is `v1=` and the hex HMAC-SHA256 of the
 * `x-pixonaut-timestamp` value, a `.`, and the raw body bytes. While a
 * secret is rotated the header carries several signatures, separated by
 * commas; any `v1` one that matches passes, and other schemes are ignored.
 *
 * An attempt sent more than `TOLERANCE_SECONDS` from now, in either
 * direction, fails, so a captured delivery cannot be replayed later.
 */
final class Signature
{
    public const TIMESTAMP_HEADER = 'x-pixonaut-timestamp';

    public const SIGNATURE_HEADER = 'x-pixonaut-signature';

    /** The only scheme this release checks; a signature of another is skipped. */
    public const SCHEME = 'v1';

    public const TOLERANCE_SECONDS = 300;

    private const ALGORITHM = 'sha256';

    /** Separates the signatures of a header that carries several. */
    private const LIST_SEPARATOR = ',';

    /** Separates a signature's scheme from its value. */
    private const SCHEME_SEPARATOR = '=';

    /** Separates the timestamp from the body in what is signed. */
    private const SIGNED_SEPARATOR = '.';

    /**
     * Whether the attempt was signed with `$secret` within the tolerance.
     *
     * @param string $secret          The endpoint's signing secret.
     * @param string $timestamp       The `x-pixonaut-timestamp` header: Unix seconds.
     * @param string $signatureHeader The `x-pixonaut-signature` header.
     * @param string $rawBody         The request body, exactly as received.
     * @param int    $now             Unix time.
     */
    public static function verify(string $secret, string $timestamp, string $signatureHeader, string $rawBody, int $now): bool
    {
        if ($secret === '' || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac(self::ALGORITHM, $timestamp . self::SIGNED_SEPARATOR . $rawBody, $secret);
        foreach (explode(self::LIST_SEPARATOR, $signatureHeader) as $entry) {
            $parts = explode(self::SCHEME_SEPARATOR, trim($entry), 2);
            if (count($parts) === 2 && $parts[0] === self::SCHEME && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
