<?php

declare(strict_types=1);

/*
 * Fails when a class the SDK or its dependencies declare can be loaded
 * without the prefix: the prefixed copy must stand alone.
 *
 *     php -d auto_prepend_file=<autoload> unprefixed.php
 *
 * Kept out of the prefixed app: the tools would prefix the names below.
 */

$unprefixed = array_filter(
    ['Pixonaut\Sdk\Pixonaut', 'Pixonaut\Sdk\Webhooks\Verifier', 'Nyholm\Psr7\Factory\Psr17Factory', 'Symfony\Component\HttpClient\Psr18Client', 'Psr\Http\Client\ClientInterface'],
    static fn(string $class): bool => class_exists($class) || interface_exists($class),
);
if ($unprefixed !== []) {
    fwrite(STDERR, 'Loadable without the prefix: ' . implode(', ', $unprefixed) . "\n");
    exit(1);
}
echo "Nothing loads without the prefix.\n";
