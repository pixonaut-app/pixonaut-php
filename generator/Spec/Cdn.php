<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Reads API releases from Pixonaut's public CDN, which needs no token.
 *
 * Each release sits under `<origin>/api/<version>/` with an `integrity.json`
 * naming every file's Subresource Integrity hash; a file is accepted only
 * when it answers 2xx, is JSON, and matches that hash.
 */
final class Cdn
{
    public const SPEC_FILE = 'openapi.json';

    public const VECTORS_FILE = 'webhook-signature-vectors.json';

    private const INTEGRITY_FILE = 'integrity.json';

    /** The hash `integrity.json` gives each file, in SRI form. */
    private const INTEGRITY_ALGORITHM = 'sha384';

    private const HTTP_NOT_FOUND = 404;

    private const HTTP_SUCCESS_MIN = 200;

    private const HTTP_SUCCESS_MAX = 299;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly string $origin = SpecLock::CDN_ORIGIN,
    ) {}

    /**
     * Downloads and verifies API release `$version`.
     *
     * @throws SpecException when the release is missing, a file is not JSON,
     *                       or a file does not match its integrity hash.
     */
    public function release(string $version): Release
    {
        $base = SpecLock::baseUrl($this->origin, $version);
        $integrity = json_decode($this->download($base . self::INTEGRITY_FILE, "API {$version} is not published"), true);
        $hashes = is_array($integrity) ? ($integrity['files'] ?? null) : null;
        if (!is_array($hashes)) {
            throw new SpecException("{$base}" . self::INTEGRITY_FILE . ' has no "files" map.');
        }

        $spec = $this->verified($base, self::SPEC_FILE, $hashes);
        $vectors = $this->verified($base, self::VECTORS_FILE, $hashes);

        $declared = SpecFiles::infoVersion($spec);
        if ($declared !== $version) {
            throw new SpecException(sprintf(
                'API %s: %s declares info.version %s.',
                $version,
                self::SPEC_FILE,
                $declared ?? '(none)',
            ));
        }

        return new Release($version, $spec, $vectors);
    }

    /**
     * @param array<mixed> $hashes
     */
    private function verified(string $base, string $name, array $hashes): string
    {
        $expected = $hashes[$name] ?? null;
        if (!is_string($expected)) {
            throw new SpecException("{$base}" . self::INTEGRITY_FILE . " names no hash for {$name}.");
        }

        $bytes = $this->download($base . $name);
        $actual = self::INTEGRITY_ALGORITHM . '-' . base64_encode(hash(self::INTEGRITY_ALGORITHM, $bytes, true));
        if (!hash_equals($expected, $actual)) {
            throw new SpecException("{$base}{$name} does not match its integrity hash: expected {$expected}, got {$actual}.");
        }

        return $bytes;
    }

    /**
     * The body of `$url`, which must answer 2xx with JSON.
     *
     * @param string|null $notFound What a 404 means, when it means more than
     *                              a failed request.
     */
    private function download(string $url, ?string $notFound = null): string
    {
        try {
            $response = $this->client->sendRequest($this->requests->createRequest('GET', $url));
        } catch (ClientExceptionInterface $exception) {
            throw new SpecException("{$url} could not be fetched: {$exception->getMessage()}", 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status === self::HTTP_NOT_FOUND && $notFound !== null) {
            throw new SpecException("{$notFound}: {$url} answered {$status}.");
        }
        if ($status < self::HTTP_SUCCESS_MIN || $status > self::HTTP_SUCCESS_MAX) {
            throw new SpecException("{$url} answered {$status}.");
        }

        $body = (string) $response->getBody();
        if (!SpecFiles::isJson($body)) {
            throw new SpecException("{$url} did not answer JSON.");
        }

        return $body;
    }
}
