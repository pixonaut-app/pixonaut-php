<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Release;

use UnexpectedValueException;

/**
 * Each release's notes open with the API release it targets: the first
 * line under the version's heading in `CHANGELOG.md`, in the release pull
 * request and in the GitHub Release. Release-please writes the rest.
 *
 * Needs nothing but PHP, so the release workflow runs it without
 * installing the package.
 */
final class ReleaseNotes
{
    /** A release-please version heading: `## [1.0.0](…) (2026-11-06)` or `## 1.0.0 (…)`. */
    private const HEADING = '/^##\s+\[?\d+\.\d+\.\d+[^\n]*$/m';

    /** The line that names the API release, `%s` the release. */
    private const LINE = 'Targets the Pixonaut API %s.';

    /** Matches a line `line()` writes, capturing the API release it names. */
    public const LINE_PATTERN = '/^Targets the Pixonaut API (\d+\.\d+\.\d+)\.$/';

    /**
     * The line that names the API release.
     */
    public static function line(string $apiVersion): string
    {
        return sprintf(self::LINE, $apiVersion);
    }

    /**
     * The API release `composer.json` names in `extra.pixonaut.api-version`.
     *
     * @throws UnexpectedValueException When it names none.
     */
    public static function apiVersion(string $composerJson): string
    {
        $composer = json_decode($composerJson, true);
        $version = is_array($composer) && is_array($composer['extra'] ?? null) && is_array($composer['extra']['pixonaut'] ?? null)
            ? ($composer['extra']['pixonaut']['api-version'] ?? null)
            : null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UnexpectedValueException('composer.json names no API version in extra.pixonaut.api-version.');
        }

        return $version;
    }

    /**
     * The notes with the API line first under the newest version's
     * heading, or first of all when they have no heading. Notes that
     * already open with it are returned as they are.
     */
    public static function annotate(string $notes, string $apiVersion): string
    {
        $line = self::line($apiVersion);
        if (preg_match(self::HEADING, $notes, $heading, PREG_OFFSET_CAPTURE) !== 1) {
            return ltrim($notes) === '' ? $line . "\n" : $line . "\n\n" . ltrim($notes);
        }
        $end = $heading[0][1] + strlen($heading[0][0]);
        $rest = substr($notes, $end);
        if (self::firstLine($rest) === $line) {
            return $notes;
        }

        return substr($notes, 0, $end) . "\n\n" . $line . "\n\n" . ltrim($rest, "\n");
    }

    /**
     * The first line of text under the newest version's heading, or null
     * when the notes have no heading.
     */
    public static function newestFirstLine(string $notes): ?string
    {
        if (preg_match(self::HEADING, $notes, $heading, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return self::firstLine(substr($notes, $heading[0][1] + strlen($heading[0][0])));
    }

    /**
     * The API release the newest version's notes open with, or null when
     * their first line names none.
     */
    public static function newestApiVersion(string $notes): ?string
    {
        $line = self::newestFirstLine($notes);

        return $line !== null && preg_match(self::LINE_PATTERN, $line, $match) === 1 ? $match[1] : null;
    }

    private static function firstLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return '';
    }
}
