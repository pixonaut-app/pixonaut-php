<?php

declare(strict_types=1);

// The store's webhook endpoint: checks that Pixonaut signed the delivery,
// then acts on its event.

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Pixonaut\Sdk\Exception\InvalidSignature;
use Pixonaut\Sdk\Generated\Model\DesignCommittedEvent;
use Pixonaut\Sdk\Generated\Model\OrderStatusChangedEvent;
use Pixonaut\Sdk\Webhooks\Verifier;

$keysFile = getenv('PIXONAUT_KEYS_FILE') ?: __DIR__ . '/pixonaut-keys.json';
/** @var array{integrationKey: string, webhookSecret: string} $keys */
$keys = json_decode((string) file_get_contents($keysFile), true, flags: JSON_THROW_ON_ERROR);

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

try {
    $event = (new Verifier($keys['webhookSecret']))->verify($request);
} catch (InvalidSignature) {
    // Not from Pixonaut, or replayed: do not act on the body.
    http_response_code(401);

    return;
}

// A retry or a redelivery carries the same id: skip one already handled.
// (This example keeps no record of them.)

$typed = $event->typed();
match (true) {
    $typed instanceof DesignCommittedEvent => error_log("Design {$typed->payload->designId} committed."),
    $typed instanceof OrderStatusChangedEvent => error_log("Order {$typed->payload->orderId} is {$typed->payload->status}."),
    // An event this store does not act on, or a type added to the API after
    // this release (an `UnknownEvent`): acknowledge it, or Pixonaut resends it.
    default => null,
};

http_response_code(204);
