<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests;

/**
 * Keeps the test run off the network beyond loopback: every HTTP client
 * that honours the proxy variables (curl, and with it Guzzle and Symfony's
 * client) and PHP's own `http` stream wrapper are sent to a proxy that
 * refuses every connection, except for loopback hosts, which they reach
 * directly. A request to `api.pixonaut.app` or any other real host fails
 * before it leaves the machine.
 *
 * CI also runs the suites in a container with no network but loopback;
 * this guard holds the same line in a local run.
 */
final class NetworkGuard
{
    /**
     * Port 1 on loopback: nothing listens there, so a connection is refused
     * at once rather than timing out.
     */
    public const DEAD_PROXY = 'http://127.0.0.1:1';

    /** Hosts reached directly, past the dead proxy. */
    public const LOOPBACK = 'localhost,127.0.0.1,::1';

    private const PROXY_VARIABLES = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'];

    private const NO_PROXY_VARIABLES = ['NO_PROXY', 'no_proxy'];

    public static function install(): void
    {
        foreach (self::PROXY_VARIABLES as $name) {
            self::set($name, self::DEAD_PROXY);
        }
        foreach (self::NO_PROXY_VARIABLES as $name) {
            self::set($name, self::LOOPBACK);
        }
        // The stream wrapper ignores the variables; the tests reach loopback
        // over curl, so it is refused everything.
        stream_context_set_default([
            'http' => ['proxy' => str_replace('http://', 'tcp://', self::DEAD_PROXY), 'request_fulluri' => true],
        ]);
    }

    private static function set(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
