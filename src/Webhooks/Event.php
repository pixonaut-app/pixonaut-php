<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Webhooks;

use JsonException;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Generated\EventType;
use Pixonaut\Sdk\Generated\Model\WebhookEvent;
use Pixonaut\Sdk\Model\Decode;
use Pixonaut\Sdk\Model\Model;
use stdClass;
use UnexpectedValueException;

/**
 * The envelope Pixonaut delivers, and `GET /v1/events` lists:
 * `{id, type, createdAt, payload}`. A retry or a redelivery of an event
 * carries the same id; dropping one already handled is the receiver's job.
 *
 * An automation's webhook step (`automation.webhook`) sends whatever JSON it
 * wrote as its payload, or none: `$payload` is then that JSON when it is an
 * object, `[]` otherwise, and `rawPayload()` is what was sent.
 */
final class Event
{
    /** The deepest JSON a delivery's body is decoded to. */
    private const MAX_DEPTH = 512;

    /**
     * @param string               $id        The event's id.
     * @param string               $type      Its type, e.g. `order.status_changed` (`EventType`).
     * @param int                  $createdAt When it happened, in milliseconds since the Unix epoch.
     * @param array<string, mixed> $payload   The type's payload.
     * @param array<string, mixed> $envelope  The envelope as received, unknown fields included.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $createdAt,
        public readonly array $payload,
        private readonly array $envelope,
    ) {}

    /**
     * Reads a delivery's body.
     *
     * The body is read as JSON objects as well, so a payload object whose
     * keys are all numbers (`{"0": …}`) is still an object, and an empty
     * list (`[]`) is not one.
     *
     * @throws UnexpectedValueException When it is not JSON, or not an event envelope.
     */
    public static function fromJson(string $body): self
    {
        try {
            $objects = json_decode($body, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            $data = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('The body is not JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!$objects instanceof stdClass || !is_array($data)) {
            throw new UnexpectedResponse('$', 'expected object');
        }
        /** @var array<string, mixed> $data */

        return self::read($data, '$', property_exists($objects, 'payload') ? $objects->payload instanceof stdClass : null);
    }

    /**
     * Reads a decoded envelope, as a delivery's body or an item of
     * `GET /v1/events` holds it.
     *
     * Decoded into PHP arrays, a payload object whose keys are all numbers
     * cannot be told from a list, and is refused; `fromJson()` tells them apart.
     *
     * @param array<string, mixed> $data
     * @param string               $path Where the envelope is in the document, for the error.
     *
     * @throws UnexpectedResponse When the id or type is missing or empty, `createdAt` is not an integer, or the payload is not an object.
     */
    public static function fromArray(array $data, string $path = '$'): self
    {
        return self::read($data, $path, null);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool|null            $payloadIsObject Whether the JSON payload was an object; null when only the arrays are known.
     */
    private static function read(array $data, string $path, ?bool $payloadIsObject): self
    {
        $id = self::nonEmpty(Decode::string(Decode::required($data, 'id', $path), $path . '.id'), $path . '.id');
        $type = self::nonEmpty(Decode::string(Decode::required($data, 'type', $path), $path . '.type'), $path . '.type');
        $createdAt = Decode::int(Decode::required($data, 'createdAt', $path), $path . '.createdAt');
        $payload = $data['payload'] ?? null;
        $isObject = $payloadIsObject ?? (is_array($payload) && ($payload === [] || !array_is_list($payload)));
        if ($type !== EventType::AUTOMATION_WEBHOOK) {
            Decode::required($data, 'payload', $path);
            if (!$isObject || !is_array($payload)) {
                throw new UnexpectedResponse($path . '.payload', 'expected object');
            }
        }
        /** @var array<string, mixed> $payload */
        $payload = $isObject && is_array($payload) ? $payload : [];

        return new self($id, $type, $createdAt, $payload, $data);
    }

    /**
     * The payload exactly as sent; null when there was none. For every type
     * but `automation.webhook` it is `$payload`.
     */
    public function rawPayload(): mixed
    {
        return $this->envelope['payload'] ?? null;
    }

    /**
     * The envelope as received, fields this release does not know included.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->envelope;
    }

    /**
     * The event as the class its type names (`OrderStatusChangedEvent`), with
     * its typed payload; an `UnknownEvent` for a type this release does not
     * know.
     *
     * @throws UnexpectedResponse When a known type's fields do not have the shape its class describes.
     */
    public function typed(): Model
    {
        // Built from this envelope, not decoded again, so a payload `fromJson()` read as an object stays one.
        return in_array($this->type, EventType::KNOWN, true)
            ? WebhookEvent::decode($this->envelope)
            : UnknownEvent::fromEvent($this);
    }

    private static function nonEmpty(string $value, string $path): string
    {
        return $value !== '' ? $value : throw new UnexpectedResponse($path, 'expected a non-empty string');
    }
}
