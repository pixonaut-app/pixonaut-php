# Errors

Every call that does not succeed throws `Pixonaut\Sdk\Exception\ApiException`.
Nothing is returned half done: a method either returns the answer or throws.

```php
use Pixonaut\Sdk\Exception\ApiException;

try {
    $design = $pixonaut->designs()->get($designId);
} catch (ApiException $e) {
    $e->operation;  // 'designs.get'
    $e->status;     // the HTTP status, 0 when no answer arrived
    $e->errorCode;  // Pixonaut's `error`, or one of the client's own codes
    $e->retryable;  // whether the same request may succeed later
    $e->retryAfter; // seconds Pixonaut asked you to wait, or null
    $e->requestId;  // Pixonaut's X-Request-Id: quote it to support
    $e->details;    // the rest of the error body
}
```

Branch on `errorCode`, never on the status alone: one status carries several
codes. `Pixonaut\Sdk\Generated\ErrorCode` names every code the API release
this SDK targets documents, and each resource method lists the codes its
operation can answer in its `@throws`. A code added to the API later still
arrives in `errorCode` as Pixonaut sent it, and is logged at error level
through the PSR-3 logger you pass the client, so it gets noticed.

## The client's own codes

| Code | Status | Meaning | Retryable |
| --- | --- | --- | --- |
| `transport_failed` | 0 | No answer arrived: DNS, connection, timeout, or the answer was cut short. `getPrevious()` is the PSR-18 client's exception. | yes |
| `not_connected` | 0 | The operation needs a credential the client holds none of. Nothing was sent. | no |
| `unexpected_response` | any | The answer is not what the operation answers, e.g. a proxy's HTML page, or JSON this release cannot read. | only an error status that is |

## What is retryable

`retryable` is decided in this order:

1. `idempotency_key_reused` and `invalid_idempotency_key` are never
   retryable: the request was built wrong. They are thrown as
   `IdempotencyBugException`, a subclass, so they can be caught apart.
2. An error body with a boolean `retryable` field decides.
3. Otherwise a `5xx`, `408`, `429` and `idempotency_key_in_use` are
   retryable, and every other refusal is not.

`retryAfter` is Pixonaut's `Retry-After`, in seconds, whether it was sent as
seconds or as a date.

## What the client retries itself

Only `409 idempotency_key_in_use`: Pixonaut is still answering an earlier
request with the same key. The client waits for `Retry-After` (1 second
when absent, at most 5) and sends the request again, at most
`Pixonaut::MAX_IN_USE_RESENDS` (3) times, before it throws.

Nothing else is retried, so a request is never sent twice behind your back.

## What the host should retry

- **A retryable error on a read**: send it again later, no sooner than
  `retryAfter`, with a backoff of your own when it is null.
- **A retryable error on a write with an idempotency key** (an operation
  with `prepare…()` and `send…()` methods): send the *stored* prepared
  request again, so the repeat carries the same key and body. Pixonaut makes
  the thing once. See [idempotency](idempotency.md).
- **A retryable error on any other write**, above all `transport_failed`:
  the request may have reached Pixonaut. Read what it was about before
  sending it again. Before re-sending an order, check the print's orders,
  and never submit again while an order says `outcome_unknown`.
- **Not retryable**: do not send the same request again. Show the code,
  change the request, or reconnect on `unauthorized`.
- **`IdempotencyBugException`**: a bug in the integration. Log it with the
  request id; sending it again never helps.

## Credentials refused

A `401` or `unauthorized` calls `Listener::unauthorized()` before the
exception is thrown, so a host can mark the store disconnected in one place.
Pass a `Pixonaut\Sdk\Http\Listener` subclass as the client's last argument.

## Secrets in errors

An operation that answers a secret (an exchanged key, a webhook secret) never
keeps its error body: `details` is `['redacted' => true]`. A listener never
sees the request a transport failure carries, since it holds the credential.

## Deprecations

An answer with a `Deprecation` header is logged as a warning, naming the
operation and the `Sunset` date, and passed to `Listener::deprecated()`.
It does not fail the call. Update the SDK before the sunset.
