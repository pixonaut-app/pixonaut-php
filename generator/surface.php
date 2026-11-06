<?php

declare(strict_types=1);

/**
 * The PHP side of `bin/surface`, run in the package's container.
 *
 *     surface.php           write api-surface.txt from src/
 *     surface.php --check   fail, showing the difference, when api-surface.txt is not what src/ declares
 */

use Pixonaut\Sdk\Generator\Surface\ApiSurface;

require __DIR__ . '/../vendor/autoload.php';

/** Every generated class is loaded to be reflected. */
ini_set('memory_limit', '1G');

const EXIT_OK = 0;
const EXIT_DIFFERS = 1;
const EXIT_USAGE = 2;

$root = dirname(__DIR__);
/** @var list<string> $argv */
$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
if (!$check && $arguments !== []) {
    fwrite(STDERR, "Usage: bin/surface [--check]\n");
    exit(EXIT_USAGE);
}

$file = $root . '/api-surface.txt';
$surface = (new ApiSurface($root . '/src', 'Pixonaut\\Sdk'))->render();

if (!$check) {
    file_put_contents($file, $surface);
    echo "Wrote api-surface.txt.\n";
    exit(EXIT_OK);
}

$committed = is_file($file) ? (string) file_get_contents($file) : '';
if ($committed === $surface) {
    echo "api-surface.txt is up to date.\n";
    exit(EXIT_OK);
}
$was = array_flip(explode("\n", $committed));
$is = array_flip(explode("\n", $surface));
fwrite(STDERR, "api-surface.txt is stale: run bin/surface and commit the result.\n");
foreach (array_keys(array_diff_key($was, $is)) as $line) {
    fwrite(STDERR, '- ' . $line . "\n");
}
foreach (array_keys(array_diff_key($is, $was)) as $line) {
    fwrite(STDERR, '+ ' . $line . "\n");
}
exit(EXIT_DIFFERS);
