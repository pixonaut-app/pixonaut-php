<?php

declare(strict_types=1);

/**
 * The PHP side of `bin/spec`, run in the package's container. See
 * `Pixonaut\Sdk\Generator\Spec\SpecCommand` for the commands.
 */

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Generator\Spec\Cdn;
use Pixonaut\Sdk\Generator\Spec\SpecCommand;
use Pixonaut\Sdk\Generator\Spec\SpecFiles;

require __DIR__ . '/../vendor/autoload.php';

/** Seconds a CDN request may take; the spec is several megabytes. */
const REQUEST_TIMEOUT_SECONDS = 60;

$command = new SpecCommand(
    new SpecFiles(dirname(__DIR__) . '/spec'),
    static fn(string $origin): Cdn => new Cdn(
        new Client(['timeout' => REQUEST_TIMEOUT_SECONDS, 'http_errors' => false]),
        new Psr17Factory(),
        $origin,
    ),
    static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    },
);

/** @var list<string> $argv */
exit($command->run(array_slice($argv, 1)));
