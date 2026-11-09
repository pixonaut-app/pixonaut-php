<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

/**
 * `spec/spec.lock.json`: the API release the SDK is built from, its address
 * on the CDN, and the sha256 of the two pinned files.
 *
 * A reference pinned from a local copy (`bin/spec pin --from`), because the
 * API answers it before a release publishes it, is marked `published: false`:
 * the CDN has no copy to check it against.
 */
final class SpecLock
{
    /** The public CDN each API release is published on, immutably. */
    public const CDN_ORIGIN = 'https://cdn.pixonaut.app';

    private const API_PATH = '/api/';

    /** A release version, as the CDN path names it: semver, no leading `v`. */
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    public function __construct(
        public readonly string $version,
        public readonly string $baseUrl,
        public readonly string $sha256,
        public readonly string $vectorsSha256,
        public readonly bool $published = true,
    ) {}

    public static function forRelease(Release $release, bool $published = true): self
    {
        return new self(
            $release->version,
            self::baseUrl(self::CDN_ORIGIN, $release->version),
            hash('sha256', $release->spec),
            hash('sha256', $release->vectors),
            $published,
        );
    }

    /**
     * Where `$origin` serves API release `$version`, with a trailing slash.
     */
    public static function baseUrl(string $origin, string $version): string
    {
        return rtrim($origin, '/') . self::API_PATH . self::assertVersion($version) . '/';
    }

    /**
     * @throws SpecException when `$version` is not a release version.
     */
    public static function assertVersion(string $version): string
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new SpecException(sprintf('"%s" is not an API release version, such as 1.0.0.', $version));
        }

        return $version;
    }

    /**
     * @throws SpecException when `$json` is not a lock.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        $vectors = is_array($data) ? ($data['webhookSignatureVectors'] ?? null) : null;
        if (
            !is_array($data) || !is_array($vectors)
            || !is_string($data['version'] ?? null)
            || !is_string($data['baseUrl'] ?? null)
            || !is_string($data['sha256'] ?? null)
            || !is_string($vectors['sha256'] ?? null)
        ) {
            throw new SpecException('The lock is not a spec lock: it needs version, baseUrl, sha256 and webhookSignatureVectors.sha256.');
        }
        $published = $data['published'] ?? true;
        if (!is_bool($published)) {
            throw new SpecException('The lock\'s published, when present, is true or false.');
        }

        return new self($data['version'], $data['baseUrl'], $data['sha256'], $vectors['sha256'], $published);
    }

    /**
     * The lock as written to disk: the same lock always gives the same bytes,
     * so pinning the release already pinned changes nothing. `published` is
     * written only when false, so a published pin's lock keeps its bytes.
     */
    public function toJson(): string
    {
        $lock = [
            'version' => $this->version,
            'baseUrl' => $this->baseUrl,
            'sha256' => $this->sha256,
            'webhookSignatureVectors' => ['sha256' => $this->vectorsSha256],
        ];
        if (!$this->published) {
            $lock['published'] = false;
        }

        return json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
