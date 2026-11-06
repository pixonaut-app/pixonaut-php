<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Webhooks;

use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Model\Model;

/**
 * An event of a type this release does not know, added to the API after it:
 * its envelope, with the payload as sent. A receiver that does not handle
 * the type acknowledges it and moves on.
 */
final class UnknownEvent implements Model
{
    /**
     * @param string               $id        The event's id.
     * @param string               $type      Its type, which this release has no class for.
     * @param int                  $createdAt When it happened, in milliseconds since the Unix epoch.
     * @param array<string, mixed> $payload   Its payload, undecoded.
     * @param array<string, mixed> $envelope  The envelope as received.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $createdAt,
        public readonly array $payload,
        private readonly array $envelope,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param string               $path Where the envelope is in the document, for the error.
     *
     * @throws UnexpectedResponse When it is not an event envelope.
     */
    public static function fromArray(array $data, string $path = '$'): self
    {
        $event = Event::fromArray($data, $path);

        return new self($event->id, $event->type, $event->createdAt, $event->payload, $event->raw());
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->envelope;
    }
}
