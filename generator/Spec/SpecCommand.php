<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

use Closure;

/**
 * `bin/spec`: pins an API release from the CDN, or checks the pinned one.
 *
 *     pin [<version>] [--cdn <origin>] [--previous-out <file>]
 *     check [--online] [--cdn <origin>]
 */
final class SpecCommand
{
    public const EXIT_OK = 0;

    public const EXIT_FAILED = 1;

    public const EXIT_USAGE = 2;

    private const USAGE = <<<'TXT'
        Usage:
          bin/spec pin [<version>] [--cdn <origin>]
          bin/spec check [--online] [--cdn <origin>]
        TXT;

    /** @var Closure(string): void */
    private readonly Closure $out;

    /** @var Closure(string): Cdn */
    private readonly Closure $cdn;

    /**
     * @param Closure(string): Cdn $cdn  The CDN client for an origin.
     * @param Closure(string): void $out Prints one line.
     */
    public function __construct(
        private readonly SpecFiles $files,
        Closure $cdn,
        Closure $out,
    ) {
        $this->cdn = $cdn;
        $this->out = $out;
    }

    /**
     * @param list<string> $arguments The arguments after the script name.
     */
    public function run(array $arguments): int
    {
        try {
            [$command, $positionals, $options] = self::parse($arguments);

            return match ($command) {
                'pin' => $this->pin($positionals, $options),
                'check' => $this->check($positionals, $options),
                default => $this->usage(),
            };
        } catch (UsageException $exception) {
            ($this->out)($exception->getMessage());

            return $this->usage();
        } catch (SpecException $exception) {
            ($this->out)($exception->getMessage());

            return self::EXIT_FAILED;
        }
    }

    /**
     * Everything is downloaded and verified before any file changes, so a
     * failed pin leaves the previous one in place.
     *
     * @param list<string> $positionals
     * @param array<string, string|true> $options
     */
    private function pin(array $positionals, array $options): int
    {
        self::allowOnly($options, ['cdn', 'previous-out']);
        if (count($positionals) > 1) {
            throw new UsageException('pin takes at most one version.');
        }

        $previous = $this->files->hasLock() ? $this->files->lock() : null;
        $version = $positionals[0] ?? $previous?->version;
        if ($version === null) {
            throw new UsageException('Nothing is pinned yet: name the version to pin.');
        }

        $release = $this->cdnFor($options)->release(SpecLock::assertVersion($version));
        $next = SpecLock::forRelease($release);

        $previousOut = self::stringOption($options, 'previous-out');
        if ($previous !== null && $previous->version !== $version && $previousOut !== null) {
            // For the caller's diff of what the new release changes.
            if (file_put_contents($previousOut, $this->files->spec()) === false) {
                throw new SpecException("Could not write {$previousOut}.");
            }
        }

        $this->files->write($release);

        if ($previous === null) {
            ($this->out)("Pinned API {$version}.");
        } elseif ($previous->version !== $version) {
            ($this->out)("Pinned API {$version} (was {$previous->version}).");
        } elseif ($previous == $next) {
            ($this->out)("API {$version} is already pinned; nothing changed.");
        } else {
            ($this->out)("Re-pinned API {$version}: the pinned files had drifted from the release.");
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $positionals
     * @param array<string, string|true> $options
     */
    private function check(array $positionals, array $options): int
    {
        self::allowOnly($options, ['online', 'cdn']);
        if ($positionals !== []) {
            throw new UsageException('check takes no version: it checks the pinned one.');
        }

        $problems = $this->files->problems();
        if ($problems === [] && isset($options['online'])) {
            $lock = $this->files->lock();
            $release = $this->cdnFor($options)->release($lock->version);
            if (SpecLock::forRelease($release) != $lock) {
                $problems[] = "The pinned files differ from API {$lock->version} on the CDN; run bin/spec pin {$lock->version}.";
            }
        }

        foreach ($problems as $problem) {
            ($this->out)($problem);
        }
        if ($problems !== []) {
            ($this->out)('The pinned spec is changed only by bin/spec pin.');

            return self::EXIT_FAILED;
        }

        $lock = $this->files->lock();
        ($this->out)(isset($options['online'])
            ? "API {$lock->version}: the pinned files are the published release."
            : "API {$lock->version}: the pinned files match the lock.");

        return self::EXIT_OK;
    }

    private function usage(): int
    {
        ($this->out)(self::USAGE);

        return self::EXIT_USAGE;
    }

    /**
     * @param array<string, string|true> $options
     */
    private function cdnFor(array $options): Cdn
    {
        return ($this->cdn)(self::stringOption($options, 'cdn') ?? SpecLock::CDN_ORIGIN);
    }

    /**
     * @param array<string, string|true> $options
     */
    private static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if ($value === true) {
            throw new UsageException("--{$name} needs a value.");
        }

        return $value;
    }

    /**
     * @param array<string, string|true> $options
     * @param list<string> $allowed
     */
    private static function allowOnly(array $options, array $allowed): void
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new UsageException("Unknown option --{$name}.");
            }
        }
    }

    /**
     * `--name value`, `--name=value` and bare `--flag`; `--online` is the
     * only flag, every other option takes a value.
     *
     * @param list<string> $arguments
     *
     * @return array{string, list<string>, array<string, string|true>}
     */
    private static function parse(array $arguments): array
    {
        $flags = ['online'];
        $command = array_shift($arguments) ?? '';
        $positionals = [];
        $options = [];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!str_starts_with($argument, '--')) {
                $positionals[] = $argument;
                continue;
            }
            $name = substr($argument, 2);
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
                $options[$name] = $value;
            } elseif (in_array($name, $flags, true) || $arguments === []) {
                $options[$name] = true;
            } else {
                $options[$name] = array_shift($arguments);
            }
        }

        return [$command, $positionals, $options];
    }
}
