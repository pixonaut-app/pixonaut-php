<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use InvalidArgumentException;

/**
 * A request as it is sent, kept so it can be sent again unchanged.
 *
 * The body is encoded and the idempotency key chosen once, when the request is
 * prepared. A host that stores the snapshot (`toArray()`) and later sends
 * `fromArray()` of it sends the same bytes under the same key, so Pixonaut
 * recognises a repeat. The credential is not part of it; it is added at every
 * send.
 */
final class PreparedRequest
{
    private const FIELDS = ['operation', 'target', 'body', 'idempotencyKey', 'headers'];

    /**
     * @param string      $operation      Operation id, e.g. `designs.commit`.
     * @param string      $target         Path and query, e.g. `/v1/designs/d_1?v=2`.
     * @param string|null $body           JSON body, or null for none.
     * @param string|null $idempotencyKey `Idempotency-Key`, or null when the operation takes none.
     * @param array<string, string> $headers Headers the operation declares, by lower-case name, e.g. `prefer`.
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $target,
        public readonly ?string $body = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $headers = [],
    ) {}

    /**
     * The request, when it is one of `$operation`'s: a typed method sending a
     * stored request checks it before decoding the answer as its own.
     *
     * @throws InvalidArgumentException When it was prepared for another operation.
     */
    public function expect(string $operation): self
    {
        if ($this->operation !== $operation) {
            throw new InvalidArgumentException(sprintf('The request was prepared for %s, not %s.', $this->operation, $operation));
        }

        return $this;
    }

    /**
     * @return array{operation: string, target: string, body: string|null, idempotencyKey: string|null, headers: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'target' => $this->target,
            'body' => $this->body,
            'idempotencyKey' => $this->idempotencyKey,
            'headers' => $this->headers,
        ];
    }

    /**
     * @param array<mixed> $data What `toArray()` returned.
     *
     * @throws InvalidArgumentException When a field is missing or has the wrong type.
     */
    public static function fromArray(array $data): self
    {
        foreach (self::FIELDS as $field) {
            // A lost key would turn a repeat into a second write, so a null
            // must be stored, not implied by a missing field.
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(sprintf('The request snapshot has no "%s".', $field));
            }
        }
        $operation = $data['operation'];
        $target = $data['target'];
        $body = $data['body'];
        $key = $data['idempotencyKey'];
        $headers = $data['headers'];
        if (!is_string($operation) || !is_string($target) || !($body === null || is_string($body)) || !($key === null || is_string($key)) || !self::isHeaderMap($headers)) {
            throw new InvalidArgumentException('The request snapshot is malformed.');
        }

        return new self($operation, $target, $body, $key, $headers);
    }

    /**
     * @phpstan-assert-if-true array<string, string> $headers
     */
    private static function isHeaderMap(mixed $headers): bool
    {
        if (!is_array($headers)) {
            return false;
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return false;
            }
        }

        return true;
    }
}
