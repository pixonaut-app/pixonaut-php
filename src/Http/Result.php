<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use Closure;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Throwable;

/**
 * A successful answer.
 */
final class Result
{
    /** @var array<string, string> Keyed by lower-case name. */
    public readonly array $headers;

    /**
     * @param string                $operation   Operation id.
     * @param int                   $status      HTTP status.
     * @param array<string, string> $headers     Response headers; names are matched case-insensitively.
     * @param string                $body        Raw body; empty when it is left unread in `$stream`.
     * @param Deprecation|null      $deprecation The deprecation notice the response carried.
     * @param bool                  $sensitive   Whether the operation's answers carry secrets.
     * @param StreamInterface|null  $stream      The unread body of an answer read as it arrives, e.g. server-sent events.
     */
    public function __construct(
        public readonly string $operation,
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly ?Deprecation $deprecation = null,
        private readonly bool $sensitive = false,
        private readonly ?StreamInterface $stream = null,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->header('X-Request-Id');
    }

    /**
     * Whether Pixonaut answered a repeated idempotent request with what the
     * first one made.
     */
    public function replayed(): bool
    {
        return $this->header('Idempotent-Replayed') === 'true';
    }

    /**
     * The body decoded as JSON, whatever value it holds.
     *
     * @throws ApiException `unexpected_response` when the body is not JSON.
     */
    public function json(): mixed
    {
        $decoded = json_decode($this->body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw $this->unexpected();
        }

        return $decoded;
    }

    /**
     * The body decoded as JSON and turned into what the operation answers,
     * e.g. its model.
     *
     * @template T
     *
     * @param Closure(mixed): T $decoder
     *
     * @return T
     *
     * @throws ApiException `unexpected_response` when the body is not JSON, or
     *                      not the shape the decoder knows; the
     *                      `UnexpectedResponse` naming the JSON path is its previous.
     */
    public function decode(Closure $decoder): mixed
    {
        $json = $this->json();
        try {
            return $decoder->__invoke($json);
        } catch (UnexpectedResponse $e) {
            throw $this->unexpected($e);
        }
    }

    /**
     * The body as the file it is, with its content type.
     */
    public function binary(): Binary
    {
        return new Binary($this->header('Content-Type') ?? '', $this->body, $this->requestId());
    }

    /**
     * The body as server-sent events, read as the caller iterates.
     */
    public function eventStream(): EventStream
    {
        return new EventStream($this->stream ?? $this->body, $this->requestId(), $this->operation);
    }

    /**
     * The body decoded as a JSON object (`{}` included; `[]` is not one).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException `unexpected_response` when the body is not a JSON object.
     */
    public function object(): array
    {
        // Decoded as objects first: associative decoding turns a list into an array too.
        if (!json_decode($this->body) instanceof stdClass) {
            throw $this->unexpected();
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->body, true);

        return $decoded;
    }

    /**
     * The body decoded as a JSON list.
     *
     * @return list<mixed>
     *
     * @throws ApiException `unexpected_response` when the body is not a JSON list.
     */
    public function list(): array
    {
        $decoded = json_decode($this->body);
        if (!is_array($decoded)) {
            throw $this->unexpected();
        }
        /** @var list<mixed> $list */
        $list = json_decode($this->body, true);

        return $list;
    }

    /**
     * The error for an answer that does not decode into what the operation
     * answers.
     */
    public function unexpected(?Throwable $previous = null): ApiException
    {
        return new ApiException(
            $this->operation,
            $this->status,
            ApiException::UNEXPECTED_RESPONSE,
            false,
            null,
            $this->requestId(),
            $this->sensitive ? ErrorMapper::REDACTED : ErrorMapper::excerpt($this->body),
            $previous,
        );
    }
}
