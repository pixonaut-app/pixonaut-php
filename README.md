# pixonaut-php

PHP SDK for the [Pixonaut](https://pixonaut.app) public API, for PHP
integrations such as WooCommerce plugins. It depends only on the PSR HTTP
and logging interfaces, so it works with any PSR-18 client, and it can be
prefixed with Strauss or PHP-Scoper.

**Pre-release.** Nothing is published yet and the API may change until 1.0.0.

## Requirements

PHP 8.1 or newer.

```sh
composer require pixonaut/pixonaut-php
```

## Webhooks

Check that a delivery was signed by Pixonaut recently, then read its event:

```php
use Pixonaut\Sdk\Exception\InvalidSignature;
use Pixonaut\Sdk\Generated\Model\OrderStatusChangedEvent;
use Pixonaut\Sdk\Webhooks\Verifier;

try {
    $event = (new Verifier($endpointSecret))->verify($request); // a PSR-7 ServerRequestInterface
} catch (InvalidSignature) {
    // Answer 401, and do not act on the body.
    return;
}

$typed = $event->typed();
if ($typed instanceof OrderStatusChangedEvent) {
    $status = $typed->payload->status;
}
```

Without PSR-7, `Signature::verify($secret, $timestamp, $signatureHeader,
$rawBody, time())` checks the `x-pixonaut-timestamp` and
`x-pixonaut-signature` headers against the raw body, and
`Event::fromJson($rawBody)` reads it. A delivery more than five minutes
from now, either way, fails, so a captured one cannot be replayed.

`$event->typed()` gives the event's class with its typed payload, or an
`UnknownEvent` with the payload as sent for a type added to the API after
this release; `EventType` names the types. A retry or a redelivery carries
the same `$event->id`: drop one you have already handled. An
`automation.webhook` delivery carries whatever JSON its automation wrote:
`$event->payload` holds it when it is an object, and `$event->rawPayload()`
is what was sent.

## Working on the package

Everything runs in Docker; the host needs no PHP or Composer.

```sh
bin/composer install        # install the dependencies
bin/composer check          # everything a pull request runs
bin/composer test           # the tests only
bin/composer format         # fix the code style
PHP_VERSION=8.5 bin/composer check   # on another PHP version
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the commit convention.

## License

[MIT](LICENSE)
