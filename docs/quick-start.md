# Quick start

This walks a store's integration through its first calls: connect the
store, read its templates, commit a design, handle a refusal and take a
webhook delivery. Each snippet is a file in [`examples/`](examples/) that the
package's tests run against a fake of the API, so what you read here runs.

## Install

The SDK needs PHP 8.1 or newer and a [PSR-18](https://www.php-fig.org/psr/psr-18/)
HTTP client with [PSR-17](https://www.php-fig.org/psr/psr-17/) factories.
Any works; these examples use `symfony/http-client` and `nyholm/psr7`, and
`nyholm/psr7-server` to read a webhook delivery:

```sh
composer require pixonaut/pixonaut-php symfony/http-client nyholm/psr7 nyholm/psr7-server
```

Load Composer's autoloader once, as usual (`require 'vendor/autoload.php';`).
The examples leave that line out.

The examples read two settings from the environment:

- `PIXONAUT_API_URL`: the API to talk to, `https://api.pixonaut.app` when it
  is not set.
- `PIXONAUT_KEYS_FILE`: where `connect.php` stores the store's keys and the
  others read them, `pixonaut-keys.json` next to the examples when it is not
  set. Keep it outside the web root.

## Connect the store

A merchant connects a store from Pixonaut and comes back to it with a
one-time code. Exchange the code for the store's keys, once, and keep them:
Pixonaut shows them in this answer only. The integration key is what the
store calls the API with; the webhook secret checks deliveries.

<!-- example: connect.php -->
```php
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
```

## Make the client

`Pixonaut` takes a `Config`, the PSR-18 client and PSR-17 factories, where
its credentials come from, and the operations this release knows
(`Operations::catalogue()`). `StaticCredentials` holds fixed keys; implement
`CredentialProvider` to read them from your own storage at every call, so a
rotated key takes effect without a new client.

<!-- example: client.php -->
```php
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
```

The client holds no static state: two clients with different keys never
share anything. The other examples start from this file.

## Read the templates

Each resource is a method on the client (`templates()`, `designs()`,
`orders()`, …) with one method per operation. A method returns the answer
as a typed model; an operation that answers different shapes returns a union
of them, so narrow it with `instanceof`.

<!-- example: templates.php -->
```php
<?php

declare(strict_types=1);

// Lists the store's templates, then reads the first one.

use Pixonaut\Sdk\Generated\Model\TemplatesListResponseOption1;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

// A key's list is paged: `items`, `page`, `pageSize` and `total`.
$list = $pixonaut->templates()->list(status: 'live', page: 1, pageSize: 50);
if (!$list instanceof TemplatesListResponseOption1) {
    throw new UnexpectedValueException('A key reads its templates a page at a time.');
}
foreach ($list->items as $item) {
    echo "{$item->id}: {$item->name}\n";
}

// Each id is `baseId@version`, and names that version for good.
$template = $pixonaut->templates()->get($list->items[0]->id);
echo "Read {$template->template->name}, version {$template->template->version}.\n";
```

## Commit a design, once

A write that must happen once takes an idempotency key: Pixonaut answers a
repeat of the same key and body with what the first one made. Prepare the
request, store it, then send it; when the answer is lost, send the stored
request again, later if need be. See [idempotency](idempotency.md).

<!-- example: commit.php -->
```php
<?php

declare(strict_types=1);

// Commits the design a shopper made, once, even when the answer is lost on
// the way back and the commit is sent again later.

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

// The proof token the designer handed the cart, and the cart line it is for.
$proofToken = 'proof_1';
$cartLine = 'cart-10482-1';

// One key per design to commit: a key derived from what it commits makes a
// second attempt the same request rather than a second design.
$prepared = $pixonaut->designs()->prepareCommit(
    new DesignsCommitRequestOption1(proofToken: $proofToken, externalRef: $cartLine),
    idempotencyKey: 'commit-' . $proofToken,
);

// Store it before sending: it is everything needed to send the same request
// again, key included. Here it goes to a file; a host keeps it with the
// cart line it commits.
$snapshot = json_encode($prepared->toArray(), JSON_THROW_ON_ERROR);
file_put_contents(sys_get_temp_dir() . "/pixonaut-commit-{$cartLine}.json", $snapshot);

try {
    $design = $pixonaut->designs()->sendCommit($prepared);
} catch (ApiException $e) {
    if (!$e->retryable) {
        throw $e;
    }
    // Later, from a scheduled job: send the stored request again. Pixonaut
    // answers a key it has seen with the first answer, so the design is
    // committed once.
    $stored = PreparedRequest::fromArray((array) json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR));
    $design = $pixonaut->designs()->sendCommit($stored);
}

echo "Committed design {$design->designId}.\n";
```

## Handle a refusal

Every failed call throws `ApiException`: Pixonaut refused it, answered
something this release cannot read, or could not be reached. Its
`errorCode` says which, `retryable` whether the same request may succeed
later, and `retryAfter` how long Pixonaut asked you to wait.
`IdempotencyBugException` is the one that means the request was built
wrong. See [errors](errors.md).

<!-- example: errors.php -->
```php
<?php

declare(strict_types=1);

// What a failed call tells the host, and which failures to try again.

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\IdempotencyBugException;
use Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

try {
    $template = $pixonaut->templates()->get('tshirt-basic@2');
    echo "Read {$template->template->name}.\n";
} catch (IdempotencyBugException $e) {
    // The request was built wrong: a key reused for another request, or one
    // Pixonaut does not accept. Sending it again never helps; fix the code.
    error_log("Pixonaut refused the idempotency key: {$e->getMessage()}");
} catch (ApiException $e) {
    if ($e->errorCode === ErrorCode::NOT_FOUND) {
        echo "No such template.\n";
    } elseif ($e->retryable) {
        // Pixonaut was busy or out of reach: try again later, no sooner than
        // it asked (`Retry-After`), with a backoff of your own when it did
        // not say.
        $backoffSeconds = 30;
        $wait = $e->retryAfter ?? $backoffSeconds;
        echo "Try again in {$wait} seconds ({$e->errorCode}).\n";
    } else {
        // Refused for good: show the code, and keep the request id for support.
        echo "Pixonaut refused it: {$e->errorCode} (request {$e->requestId}).\n";
    }
}
```

## Take a webhook delivery

Check the signature before reading the body, then act on the event by its
type. A type added to the API after this release reads as an
`UnknownEvent`, never an error. See [webhooks](webhooks.md).

<!-- example: webhook.php -->
```php
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
    // this release (an `UnknownEvent`): acknowledge it all the same.
    default => null,
};

http_response_code(204);
```

## Next

- [Errors](errors.md): the codes, what is retryable, and what to retry.
- [Idempotency](idempotency.md): snapshots, resends, what to store.
- [Webhooks](webhooks.md): the signature, its window, rotation, unknown events.
- [Versioning](versioning.md): how releases are numbered and which API each targets.
