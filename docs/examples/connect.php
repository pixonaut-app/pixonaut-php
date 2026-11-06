<?php

declare(strict_types=1);

// Connects the store: exchanges the one-time code Pixonaut sent the merchant
// back with for the store's keys, and stores them. Run it once, with the code:
//
//     php connect.php <code>

use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeRequest;
use Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeResponseOption1;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Pixonaut;
use Symfony\Component\HttpClient\Psr18Client;

$code = $argv[1] ?? throw new InvalidArgumentException('Usage: php connect.php <code>');

// No key yet: exchanging the code is the one call that needs none.
$factory = new Psr17Factory();
$pixonaut = new Pixonaut(
    new Config(baseUrl: getenv('PIXONAUT_API_URL') ?: Config::DEFAULT_BASE_URL),
    new Psr18Client(),
    $factory,
    $factory,
    new StaticCredentials(),
    Operations::catalogue(),
);

$connection = $pixonaut->connect()->exchangeCode(new ConnectedStoreExchangeCodeRequest(code: $code));
if (!$connection instanceof ConnectedStoreExchangeCodeResponseOption1) {
    throw new UnexpectedValueException('The code connects a member, not a store.');
}

// The keys are shown in this answer only: keep them where your host keeps
// secrets. This example writes them to a file outside the web root.
$keysFile = getenv('PIXONAUT_KEYS_FILE') ?: __DIR__ . '/pixonaut-keys.json';
file_put_contents($keysFile, json_encode([
    'integrationKey' => $connection->integrationKey->key,
    'webhookSecret' => $connection->webhook->secret,
], JSON_THROW_ON_ERROR));
chmod($keysFile, 0600);

echo "Connected to {$connection->tenantName}.\n";
