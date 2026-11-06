<?php

declare(strict_types=1);

/*
 * Runs the quick-start examples, the webhook signature vectors and a check
 * that nothing loads unprefixed, against a prefixed copy of the package:
 *
 *     php tests/Prefixed/check.php <prefixed app directory> <prefixed autoload.php>
 *
 * `tests/Prefixed/run` prepares the copy and calls it.
 */

use Pixonaut\Sdk\Tests\Docs\Examples;
use Pixonaut\Sdk\Tests\Docs\QuickStart;
use Pixonaut\Sdk\Tests\Fake\FakeServer;
use Pixonaut\Sdk\Tests\NetworkGuard;

require __DIR__ . '/../../vendor/autoload.php';

NetworkGuard::install();

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE = 2;

/** What the fixtures prefix every namespace with. */
const PREFIX = 'Pixonaut\\Fixture\\Vendor\\';

const VECTORS = __DIR__ . '/../../spec/webhook-signature-vectors.json';

/**
 * Runs a script with the prefixed autoloader prepended.
 *
 * @param list<string> $arguments
 */
function runPrefixed(string $autoload, string $script, array $arguments = []): void
{
    $command = array_map('escapeshellarg', [PHP_BINARY, '-d', 'auto_prepend_file=' . $autoload, $script, ...$arguments]);
    passthru(implode(' ', $command), $status);
    if ($status !== EXIT_OK) {
        throw new RuntimeException(sprintf('%s exited %d.', basename($script), $status));
    }
}

/** @var list<string> $argv */
$app = realpath($argv[1] ?? '');
$autoload = realpath($argv[2] ?? '');
if ($app === false || $autoload === false) {
    fwrite(STDERR, "Usage: php tests/Prefixed/check.php <prefixed app directory> <prefixed autoload.php>\n");
    exit(EXIT_USAGE);
}

// The examples must name the SDK through the prefix, or they would prove nothing.
if (!str_contains((string) file_get_contents($app . '/client.php'), PREFIX . 'Pixonaut\\Sdk\\Pixonaut')) {
    fwrite(STDERR, "The prefixed client.php does not name the prefixed SDK.\n");
    exit(EXIT_FAILED);
}

$server = FakeServer::start();
$examples = new Examples($app, $autoload, $server->baseUrl());
try {
    runPrefixed($autoload, __DIR__ . '/unprefixed.php');
    runPrefixed($autoload, $app . '/vectors.php', [(string) realpath(VECTORS)]);
    (new QuickStart($examples, $server))->all();
    echo "The quick-start runs on the prefixed copy.\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(EXIT_FAILED);
} finally {
    $examples->cleanUp();
    $server->stop();
}
exit(EXIT_OK);
