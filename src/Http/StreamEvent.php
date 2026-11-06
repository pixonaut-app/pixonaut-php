<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

/**
 * One server-sent event of an {@see EventStream}.
 */
final class StreamEvent
{
    /** The type an event without an `event` field has. */
    public const DEFAULT_TYPE = 'message';

    /**
     * @param string      $event The `event` field, or `message` when it had none.
     * @param string      $data  Its `data` lines, joined with a line feed.
     * @param string|null $id    The last `id` the stream set, at this event or before; null when none did.
     * @param int|null    $retry The last reconnection time in milliseconds the stream set (`retry`); null when none did.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $data,
        public readonly ?string $id = null,
        public readonly ?int $retry = null,
    ) {}
}
