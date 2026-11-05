<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\IdempotencyBugException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Turns an unsuccessful response, or a request that got none, into the
 * exception it means.
 *
 * @internal
 */
final class ErrorMapper
{
    /** What an error of a sensitive operation keeps of the body: nothing. */
    public const REDACTED = ['redacted' => true];

    /** Statuses that say "try again later" whatever the code. */
    private const RETRYABLE_STATUSES = [408, 429];

    /** Lowest status of a server-side failure; those may succeed when sent again. */
    private const SERVER_ERROR_MIN = 500;

    /** Longest stretch of a body kept in the details when it was not a JSON error. */
    private const RAW_BODY_EXCERPT_BYTES = 500;

    private const IDEMPOTENCY_BUGS = [ApiException::IDEMPOTENCY_KEY_REUSED, ApiException::INVALID_IDEMPOTENCY_KEY];

    /**
     * @param array<string, string> $headers Lower-case names.
     * @param int                   $now     Unix time, for a `Retry-After` given as a date.
     */
    public static function fromResponse(Operation $operation, int $status, array $headers, string $body, int $now): ApiException
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !array_is_list($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            $code = $decoded['error'];
            /** @var array<string, mixed> $details */
            $details = $decoded;
            unset($details['error']);
        } else {
            $code = ApiException::UNEXPECTED_RESPONSE;
            $details = self::excerpt($body);
        }

        $isBug = in_array($code, self::IDEMPOTENCY_BUGS, true);
        if ($isBug) {
            // Sending the same broken request again never helps, whatever the body says.
            $retryable = false;
        } elseif (isset($details['retryable']) && is_bool($details['retryable'])) {
            $retryable = $details['retryable'];
        } else {
            $retryable = $status >= self::SERVER_ERROR_MIN
                || in_array($status, self::RETRYABLE_STATUSES, true)
                || $code === ApiException::IDEMPOTENCY_KEY_IN_USE;
        }

        $retryAfter = self::retryAfter($headers['retry-after'] ?? null, $now);
        $requestId = $headers['x-request-id'] ?? null;
        if ($operation->sensitive) {
            $details = self::REDACTED;
        }

        return $isBug
            ? new IdempotencyBugException($operation->id, $status, $code, false, $retryAfter, $requestId, $details)
            : new ApiException($operation->id, $status, $code, $retryable, $retryAfter, $requestId, $details);
    }

    public static function transportFailed(Operation $operation, ClientExceptionInterface $failure): ApiException
    {
        $details = $operation->sensitive ? self::REDACTED : ['message' => $failure->getMessage()];

        return new ApiException($operation->id, 0, ApiException::TRANSPORT_FAILED, true, null, null, $details, $failure);
    }

    /**
     * The same error without its cause, for a listener: a PSR-18 exception
     * may carry the request, and with it the credential.
     */
    public static function withoutPrevious(ApiException $error): ApiException
    {
        return new ApiException($error->operation, $error->status, $error->errorCode, $error->retryable, $error->retryAfter, $error->requestId, $error->details);
    }

    /**
     * @return array{body: string}
     */
    public static function excerpt(string $body): array
    {
        return ['body' => substr($body, 0, self::RAW_BODY_EXCERPT_BYTES)];
    }

    /**
     * `Retry-After` in seconds; the header is either seconds or an HTTP date.
     */
    private static function retryAfter(?string $header, int $now): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $at = HttpDate::parse($header);

        return $at === null ? null : max(0, $at->getTimestamp() - $now);
    }
}
