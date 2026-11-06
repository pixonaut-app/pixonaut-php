<?php

declare(strict_types=1);

/**
 * Opens release notes with the API release they target, in place:
 *
 *     php generator/release-notes.php <file>
 *
 * The release workflow runs it on CHANGELOG.md, the release pull request's
 * body and the GitHub Release's notes. It needs no installed dependencies.
 */

use Pixonaut\Sdk\Generator\Release\ReleaseNotes;

require __DIR__ . '/Release/ReleaseNotes.php';

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE = 2;

/** @var list<string> $argv */
$file = $argv[1] ?? null;
if ($file === null || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php generator/release-notes.php <file>\n");
    exit(EXIT_USAGE);
}

try {
    $apiVersion = ReleaseNotes::apiVersion((string) file_get_contents(dirname(__DIR__) . '/composer.json'));
} catch (UnexpectedValueException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(EXIT_FAILED);
}
$notes = is_file($file) ? (string) file_get_contents($file) : '';
$annotated = ReleaseNotes::annotate($notes, $apiVersion);
if ($annotated !== $notes) {
    file_put_contents($file, $annotated);
}
exit(EXIT_OK);
