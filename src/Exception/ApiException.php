<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Exception;

use RuntimeException;
use Throwable;

/**
 * A Pixonaut call that did not succeed: Pixonaut refused it, answered
 * something unexpected, or could not be reached.
 *
 * The error code and the status are open sets: whatever Pixonaut answered is
 * kept, documented or not.
 */
class ApiException extends RuntimeException
{
    /** No response arrived: DNS, connection, timeout. */
    public const TRANSPORT_FAILED = 'transport_failed';

    /** The operation needs a credential the host does not hold; nothing was sent. */
    public const NOT_CONNECTED = 'not_connected';

    /** The answer is not what the operation answers. */
    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    /** Pixonaut is still answering an earlier request with the same key. */
    public const IDEMPOTENCY_KEY_IN_USE = 'idempotency_key_in_use';

    /** The key was used before for a different request. */
    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';

    /** The key is not one Pixonaut accepts. */
    public const INVALID_IDEMPOTENCY_KEY = 'invalid_idempotency_key';

    /** Pixonaut refused the credential. */
    public const UNAUTHORIZED = 'unauthorized';

    /** The client's own codes, which no spec lists. */
    public const CLIENT_CODES = [self::TRANSPORT_FAILED, self::NOT_CONNECTED, self::UNEXPECTED_RESPONSE];

    /**
     * @param string               $operation  Operation id.
     * @param int                  $status     HTTP status, 0 when no response arrived.
     * @param string               $errorCode  Pixonaut's `error`, or one of the client's own codes.
     * @param bool                 $retryable  Whether sending the same request again may succeed.
     * @param int|null             $retryAfter Seconds to wait before sending again, when Pixonaut said.
     * @param string|null          $requestId  Pixonaut's `X-Request-Id`, when a response arrived.
     * @param array<string, mixed> $details    The rest of the error body.
     */
    final public function __construct(
        public readonly string $operation,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly ?int $retryAfter = null,
        public readonly ?string $requestId = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Pixonaut %s answered %d %s', $operation, $status, $errorCode), 0, $previous);
    }
}
