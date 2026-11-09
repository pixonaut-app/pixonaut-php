<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

/**
 * The pinned release in `spec/`: `openapi.json`,
 * `webhook-signature-vectors.json` and `spec.lock.json`.
 */
final class SpecFiles
{
    public const LOCK_FILE = 'spec.lock.json';

    public function __construct(private readonly string $directory) {}

    public function hasLock(): bool
    {
        return is_file($this->path(self::LOCK_FILE));
    }

    /**
     * @throws SpecException when the lock is missing or malformed.
     */
    public function lock(): SpecLock
    {
        return SpecLock::fromJson($this->read(self::LOCK_FILE));
    }

    /**
     * @throws SpecException when the file is missing.
     */
    public function spec(): string
    {
        return $this->read(Cdn::SPEC_FILE);
    }

    /**
     * What is wrong with the pinned files, offline: each file must hash to
     * what the lock says, and the lock must name the spec's own version at
     * its address on the CDN. Empty when the pin is intact.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        try {
            $lock = $this->lock();
            $spec = $this->spec();
            $vectors = $this->read(Cdn::VECTORS_FILE);
        } catch (SpecException $exception) {
            return [$exception->getMessage()];
        }

        $problems = [];
        if (hash('sha256', $spec) !== $lock->sha256) {
            $problems[] = Cdn::SPEC_FILE . ' does not match the sha256 in ' . self::LOCK_FILE . '.';
        }
        if (hash('sha256', $vectors) !== $lock->vectorsSha256) {
            $problems[] = Cdn::VECTORS_FILE . ' does not match the sha256 in ' . self::LOCK_FILE . '.';
        }
        $declared = self::infoVersion($spec);
        if ($declared !== $lock->version) {
            $problems[] = sprintf(
                '%s pins %s, but %s declares info.version %s.',
                self::LOCK_FILE,
                $lock->version,
                Cdn::SPEC_FILE,
                $declared ?? '(none)',
            );
        }
        try {
            $expectedBaseUrl = SpecLock::baseUrl(SpecLock::CDN_ORIGIN, $lock->version);
            if ($lock->baseUrl !== $expectedBaseUrl) {
                $problems[] = sprintf('%s has baseUrl %s, expected %s.', self::LOCK_FILE, $lock->baseUrl, $expectedBaseUrl);
            }
        } catch (SpecException $exception) {
            $problems[] = self::LOCK_FILE . ': ' . $exception->getMessage();
        }

        return $problems;
    }

    /**
     * Writes `$release` and its lock. Every file is written beside its target
     * first and then renamed into place, the lock last. When a rename fails,
     * the files already replaced are put back, so the previous pin stays
     * whole; an interrupted process at worst leaves a lock that no longer
     * matches, which `check` reports.
     */
    public function write(Release $release, bool $published = true): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw new SpecException("Could not create {$this->directory}.");
        }

        $files = [
            Cdn::SPEC_FILE => $release->spec,
            Cdn::VECTORS_FILE => $release->vectors,
            self::LOCK_FILE => SpecLock::forRelease($release, $published)->toJson(),
        ];
        /** @var array<string, string> $staged partial file => target */
        $staged = [];
        /** @var array<string, string|null> $replaced target => its backup, null when it did not exist */
        $replaced = [];
        try {
            foreach ($files as $name => $bytes) {
                $partial = $this->path($name) . '.partial';
                $staged[$partial] = $this->path($name);
                if (@file_put_contents($partial, $bytes) !== strlen($bytes)) {
                    throw new SpecException("Could not write {$partial}.");
                }
            }
            foreach ($staged as $partial => $target) {
                $backup = null;
                if (file_exists($target)) {
                    $backup = $target . '.previous';
                    if (!@rename($target, $backup)) {
                        throw new SpecException("Could not move {$target} aside to {$backup}.");
                    }
                }
                $replaced[$target] = $backup;
                if (!@rename($partial, $target)) {
                    throw new SpecException("Could not move {$partial} to {$target}.");
                }
            }
        } catch (SpecException $exception) {
            self::restore($replaced);

            throw $exception;
        } finally {
            foreach ([...array_keys($staged), ...array_filter($replaced)] as $leftover) {
                if (is_file($leftover)) {
                    @unlink($leftover);
                }
            }
        }
    }

    /**
     * Puts back the targets `write()` had replaced, newest first.
     *
     * @param array<string, string|null> $replaced target => its backup
     */
    private static function restore(array $replaced): void
    {
        foreach (array_reverse($replaced, true) as $target => $backup) {
            if (is_file($target)) {
                @unlink($target);
            }
            if ($backup !== null) {
                @rename($backup, $target);
            }
        }
    }

    /**
     * `info.version` of an OpenAPI document, or null when it has none.
     */
    public static function infoVersion(string $spec): ?string
    {
        $data = json_decode($spec, true);
        $info = is_array($data) ? ($data['info'] ?? null) : null;
        $version = is_array($info) ? ($info['version'] ?? null) : null;

        return is_string($version) ? $version : null;
    }

    public static function isJson(string $bytes): bool
    {
        json_decode($bytes);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    private function read(string $name): string
    {
        $path = $this->path($name);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new SpecException("{$path} is missing; run bin/spec pin <version>.");
        }

        return $bytes;
    }
}
