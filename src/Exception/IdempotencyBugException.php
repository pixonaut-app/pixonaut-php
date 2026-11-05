<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Exception;

/**
 * Pixonaut refused the idempotency key (`idempotency_key_reused` or
 * `invalid_idempotency_key`): the request was built wrong. Sending it again
 * never helps.
 */
final class IdempotencyBugException extends ApiException {}
