<?php

declare(strict_types=1);

namespace Pixonaut\Sdk;

use Closure;
use InvalidArgumentException;
use Pixonaut\Sdk\Credentials\Credential;
use UnexpectedValueException;

/**
 * How a `Pixonaut` client talks to the API.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.pixonaut.app';

    /**
     * Server-held keys first, so a host holding several sends the one that
     * speaks for it rather than a browser-facing one.
     */
    public const DEFAULT_CREDENTIAL_PREFERENCE = [
        Credential::IntegrationKey,
        Credential::SecretKey,
        Credential::MemberKey,
        Credential::PublishableKey,
        Credential::ShareLink,
    ];

    /** A `User-Agent` product token: printable ASCII, no spaces at the ends. */
    private const PRODUCT_PATTERN = '/^[\x21-\x7e]([\x20-\x7e]*[\x21-\x7e])?\z/';

    public readonly string $baseUrl;

    /** @var list<Credential> */
    public readonly array $credentialPreference;

    public readonly ?string $origin;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string                $baseUrl              API base URL, absolute `http` or `https`.
     * @param string|null           $product              The host's `User-Agent` product token, e.g. `my-plugin/2.1`, sent before the SDK's own.
     * @param list<Credential>|null $credentialPreference Which credential to send when an operation accepts several the host holds; one it accepts but this list leaves out comes last.
     * @param string|null           $origin               The `Origin` sent with a publishable key, which is bound to it, e.g. `https://shop.example`.
     * @param (Closure(int): void)|null $sleep            Waits the given seconds; `sleep()` when null.
     * @param (Closure(): int)|null $clock                Unix time; `time()` when null.
     */
    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly ?string $product = null,
        ?array $credentialPreference = null,
        ?string $origin = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->baseUrl = self::baseUrl($baseUrl);
        if ($product !== null && preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            throw new InvalidArgumentException('The User-Agent product token must be printable ASCII.');
        }
        $this->credentialPreference = self::preference($credentialPreference ?? self::DEFAULT_CREDENTIAL_PREFERENCE);
        $this->origin = $origin === null ? null : self::origin($origin);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Waits before sending a request again.
     */
    public function sleep(int $seconds): void
    {
        $this->sleep->__invoke($seconds);
    }

    /**
     * Unix time.
     */
    public function now(): int
    {
        $now = $this->clock->__invoke();

        return is_int($now) ? $now : throw new UnexpectedValueException('The clock must return Unix time as an int.');
    }

    public function userAgent(): string
    {
        $sdk = 'pixonaut-php/' . Pixonaut::VERSION;

        return $this->product === null ? $sdk : $this->product . ' ' . $sdk;
    }

    private static function baseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (
            $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
        ) {
            throw new InvalidArgumentException(sprintf('The base URL must be an absolute http or https URL, got "%s".', $url));
        }

        return $url;
    }

    private static function origin(string $origin): string
    {
        $parts = parse_url($origin);
        if (
            $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || rtrim($parts['path'] ?? '', '/') !== ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
        ) {
            throw new InvalidArgumentException(sprintf('The origin must be a scheme, host and optional port, got "%s".', $origin));
        }

        return rtrim($origin, '/');
    }

    /**
     * @param array<mixed> $preference
     *
     * @return list<Credential>
     */
    private static function preference(array $preference): array
    {
        $seen = [];
        foreach ($preference as $credential) {
            if (!$credential instanceof Credential) {
                throw new InvalidArgumentException('The credential preference lists only Credential cases.');
            }
            if (in_array($credential, $seen, true)) {
                throw new InvalidArgumentException(sprintf('The credential preference lists %s twice.', $credential->value));
            }
            $seen[] = $credential;
        }

        return $seen;
    }
}
