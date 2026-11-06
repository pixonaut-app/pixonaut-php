# Idempotency

A write that must happen once, such as committing a design or placing an
order, takes an `Idempotency-Key`. Pixonaut remembers a key for 24 hours
after it is first sent:

| The first request | A repeat with the same key and body |
| --- | --- |
| succeeded | is answered with what the first made, marked `Idempotent-Replayed: true`, and makes nothing |
| is still being answered | `409 idempotency_key_in_use`, which the client waits out and resends itself |
| was refused (a 4xx) | runs again: a refusal made nothing |
| failed on Pixonaut's side (a 5xx) | runs again, unless the first had already made something, which it then answers |

The same key with another body, or for another operation, is refused
`idempotency_key_reused`; a key that is not 1 to 255 printable ASCII
characters, `invalid_idempotency_key`. Both are thrown as
`IdempotencyBugException`.

## Which operations take a key

Each idempotent operation has three methods on its resource:

```php
$design = $pixonaut->designs()->commit($body, idempotencyKey: $key); // prepare and send
$prepared = $pixonaut->designs()->prepareCommit($body, idempotencyKey: $key);
$design = $pixonaut->designs()->sendCommit($prepared);
```

Without `idempotencyKey`, the client makes a random UUID. That protects the
client's own resends, but a key the host does not keep cannot be sent again
after a crash. Pass your own when the write must survive one: derive it from
what the write is for (`'commit-' . $proofToken`, `'order-' . $orderNumber`),
so a second attempt is the same request rather than a second write.

## The snapshot

`prepare…()` returns a `Pixonaut\Sdk\Http\PreparedRequest`: the operation,
the path and query, the encoded body, the key and the declared headers,
fixed once. `toArray()` is a snapshot of plain strings to store;
`PreparedRequest::fromArray()` restores it.

```php
$prepared = $pixonaut->orders()->prepareCreate($order, idempotencyKey: 'order-' . $orderNumber);
$store->save($orderNumber, json_encode($prepared->toArray(), JSON_THROW_ON_ERROR));

$order = $pixonaut->orders()->sendCreate($prepared);
```

Later, from a scheduled job:

```php
$stored = PreparedRequest::fromArray(json_decode($store->load($orderNumber), true, flags: JSON_THROW_ON_ERROR));
$order = $pixonaut->orders()->sendCreate($stored);
```

The repeat sends the same bytes under the same key, so Pixonaut recognises
it. `Result::replayed()` (on `$pixonaut->send($stored)`, which returns the
raw result) says whether Pixonaut answered it from the first request.

What to store, and when:

- **Store the snapshot before the first send.** If the process dies after
  sending, the snapshot is what lets the next attempt be a repeat rather
  than a second write.
- **Store it whole.** A snapshot missing a field is refused by `fromArray()`,
  never sent without its key.
- **No credential is in it.** The key is added at every send, from the
  client's credentials, so a rotated key does not strand stored requests.
- **Keep it until the write succeeded, or was refused for good.** Resend
  within the 24 hours Pixonaut remembers the key; after that, a resend runs
  again as a new request.
- **Resend it with the method of its own operation.** `sendCreate()` checks
  the snapshot is an `orders.create` before decoding the answer as an order.

A host that sends requests through its own HTTP stack, for instance several
at once, builds each PSR-7 request with `$pixonaut->toPsrRequest($prepared)`
and reads each response with `$pixonaut->handle($prepared, $response)`, with
the same error mapping as `send()`.
