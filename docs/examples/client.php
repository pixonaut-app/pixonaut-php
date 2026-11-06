<?php

declare(strict_types=1);

// The client every other example uses: any PSR-18 client and PSR-17
// factories work; this one uses symfony/http-client and nyholm/psr7.

use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Pixonaut;
use Symfony\Component\HttpClient\Psr18Client;

$keysFile = getenv('PIXONAUT_KEYS_FILE') ?: __DIR__ . '/pixonaut-keys.json';
/** @var array{integrationKey: string, webhookSecret: string} $keys */
$keys = json_decode((string) file_get_contents($keysFile), true, flags: JSON_THROW_ON_ERROR);

$factory = new Psr17Factory();

return new Pixonaut(
    new Config(
        baseUrl: getenv('PIXONAUT_API_URL') ?: Config::DEFAULT_BASE_URL,
        product: 'my-shop/1.0', // your integration's User-Agent product token
    ),
    new Psr18Client(),
    $factory, // creates the requests
    $factory, // and their bodies
    new StaticCredentials(integrationKey: $keys['integrationKey']),
    Operations::catalogue(),
);
