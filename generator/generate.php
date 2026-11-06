<?php

declare(strict_types=1);

/**
 * The PHP side of `bin/generate`, run in the package's container.
 *
 *     generate.php           regenerate src/Generated/ from spec/openapi.json
 *     generate.php --check   fail, listing each file, when src/Generated/ is not what the spec and the generator produce
 */

use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use Pixonaut\Sdk\Generator\Codegen\GeneratorException;

require __DIR__ . '/../vendor/autoload.php';

/** The spec, decoded, and the tree printed from it, take a few hundred megabytes. */
ini_set('memory_limit', '2G');

const EXIT_OK = 0;
const EXIT_DIFFERS = 1;
const EXIT_USAGE = 2;

$root = dirname(__DIR__);
/** @var list<string> $argv */
$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
if (!$check && $arguments !== []) {
    fwrite(STDERR, "Usage: bin/generate [--check]\n");
    exit(EXIT_USAGE);
}

$spec = file_get_contents($root . '/spec/openapi.json');
if ($spec === false) {
    fwrite(STDERR, "Cannot read spec/openapi.json.\n");
    exit(EXIT_DIFFERS);
}
$generator = new Generator('Pixonaut\\Sdk\\Generated', Config::load(__DIR__ . '/config.php'));
$directory = $root . '/src/Generated';

try {
    if ($check) {
        $differences = $generator->check($spec, $directory);
        if ($differences !== []) {
            fwrite(STDERR, "src/Generated/ is not what spec/openapi.json and the generator produce:\n");
            foreach ($differences as $difference) {
                fwrite(STDERR, '  ' . $difference . "\n");
            }
            fwrite(STDERR, "Run bin/generate and commit the result.\n");
            exit(EXIT_DIFFERS);
        }
        fwrite(STDOUT, "src/Generated/ is up to date.\n");
        exit(EXIT_OK);
    }
    [$written, $removed] = $generator->write($spec, $directory);
    fwrite(STDOUT, sprintf("src/Generated/: %d files written, %d removed.\n", $written, $removed));
} catch (GeneratorException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(EXIT_DIFFERS);
}
