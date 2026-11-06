# Webhooks

Pixonaut sends each event to the store's webhook endpoint as a `POST` with a
JSON body: `id`, `type`, `createdAt` (milliseconds since the Unix epoch) and
a `payload` the type defines. Check it was signed by Pixonaut before reading
anything in it.

## Checking the signature

From a PSR-7 server request:

```php
use Pixonaut\Sdk\Exception\InvalidSignature;
use Pixonaut\Sdk\Webhooks\Verifier;

try {
    $event = (new Verifier($webhookSecret))->verify($request);
} catch (InvalidSignature) {
    // Answer 401 and do not act on the body.
}
```

Without PSR-7, pass the two headers and the raw body yourself:

```php
use Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\Sdk\Webhooks\Signature;

$body = file_get_contents('php://input');
if (!Signature::verify($webhookSecret, $_SERVER['HTTP_X_PIXONAUT_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_PIXONAUT_SIGNATURE'] ?? '', $body, time())) {
    http_response_code(401);
    exit;
}
$event = Event::fromJson($body);
```

Check the raw body exactly as it arrived: a framework that decodes and
re-encodes JSON changes the bytes, and the signature no longer matches.

The `x-pixonaut-signature` header is `v1=` followed by the hex HMAC-SHA256,
with the endpoint's secret, of the `x-pixonaut-timestamp` value, a `.`, and
the raw body. The signing secret is the `webhook.secret` the connect code
exchange answers, or the one shown when an endpoint is created.

## The window

A delivery whose timestamp is more than five minutes
(`Signature::TOLERANCE_SECONDS`) from now, in either direction, fails, so a
captured delivery cannot be replayed later. Keep the server's clock in sync.
`Verifier` takes a clock as its second argument for tests.

## Rotating the secret

While a secret is rotated, the header can carry several signatures,
separated by commas. Any `v1` signature that matches the secret you hold
passes, and a signature of another scheme is skipped, so deliveries keep
verifying while you switch to the new secret. Store it as soon as it is
issued.

## Reading the event

`$event->typed()` gives the event's class, with a typed payload:

```php
use Pixonaut\Sdk\Generated\Model\DesignCommittedEvent;
use Pixonaut\Sdk\Generated\Model\OrderStatusChangedEvent;

$typed = $event->typed();
match (true) {
    $typed instanceof DesignCommittedEvent => $designs->committed($typed->payload->designId),
    $typed instanceof OrderStatusChangedEvent => $orders->moved($typed->payload->orderId, $typed->payload->status),
    default => null,
};
```

`Pixonaut\Sdk\Generated\EventType` names every type the API release this SDK
targets sends, for code that only needs `$event->type`.

## Unknown events

A type added to the API after this release is not an error: `typed()` gives a
`Pixonaut\Sdk\Webhooks\UnknownEvent` with the payload as sent. Answer `2xx`
to an event you do not act on too, so it counts as delivered.

An `automation.webhook` delivery carries whatever JSON its automation wrote:
`$event->payload` holds it when it is an object, and `$event->rawPayload()`
is what was sent.

## Retries and duplicates

A retried delivery, like one redelivered on request, carries the same
`$event->id`: record the ids you have handled and skip a repeat. Answer
quickly, and do slow work after answering.
