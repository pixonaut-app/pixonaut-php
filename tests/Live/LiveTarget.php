<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Live;

use InvalidArgumentException;

/**
 * The Pixonaut the live smoke runs against, from the environment. Only a
 * loopback host is taken, so the smoke can never reach a shared or
 * production Pixonaut.
 */
final class LiveTarget
{
    public const URL = 'PIXONAUT_LIVE_API_URL';

    public const SECRET_KEY = 'PIXONAUT_LIVE_SECRET_KEY';

    public const INTEGRATION_KEY = 'PIXONAUT_LIVE_INTEGRATION_KEY';

    public const SKIPPED = 'The live smoke runs against a Pixonaut on this machine: set ' . self::URL . ' (a loopback URL) and '
        . self::SECRET_KEY . ', and optionally ' . self::INTEGRATION_KEY . '.';

    private function __construct(
        public readonly string $baseUrl,
        public readonly string $secretKey,
        public readonly ?string $integrationKey,
    ) {}

    /**
     * The target the variables name, or null when the URL or the secret key
     * is not set.
     *
     * @param array<string, string> $environment
     *
     * @throws InvalidArgumentException When the URL is not an `http(s)` URL of a loopback host.
     */
    public static function fromEnvironment(array $environment): ?self
    {
        $url = trim($environment[self::URL] ?? '');
        $secret = trim($environment[self::SECRET_KEY] ?? '');
        if ($url === '' || $secret === '') {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || !self::isLoopback($host)) {
            throw new InvalidArgumentException(sprintf('%s must name a loopback host (localhost, 127.0.0.0/8 or [::1]), not "%s": the live smoke never reaches a shared or production Pixonaut.', self::URL, $url));
        }
        $integration = trim($environment[self::INTEGRATION_KEY] ?? '');

        return new self(rtrim($url, '/'), $secret, $integration === '' ? null : $integration);
    }

    /**
     * Whether a URL's host is this machine's loopback, by its spelling
     * alone: a name other than `localhost` is never resolved, since it
     * could resolve anywhere.
     */
    public static function isLoopback(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if ($host === '[::1]' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }
}
