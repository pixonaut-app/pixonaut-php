<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

/**
 * Everything the fake remembers between requests, kept apart from the spec
 * so the HTTP router can store it in a file between its processes.
 */
final class FakeState
{
    /** How many requests the fake has seen; numbers each answer's `X-Request-Id`. */
    public int $requests = 0;

    /**
     * What the request broke, one line each.
     *
     * @var list<string>
     */
    public array $violations = [];

    /**
     * Each request the fake routed, in order.
     *
     * @var list<array{operation: string, method: string, target: string, status: int, replayed: bool}>
     */
    public array $log = [];

    /**
     * Answers a test set, used once each, by operation id.
     *
     * @var array<string, list<array{status: int, body: string, headers: array<string, string>}>>
     */
    public array $answers = [];

    /**
     * Faults a test set, used once each, by operation id; `*` for any.
     *
     * @var array<string, list<Fault>>
     */
    public array $faults = [];

    /**
     * Idempotency keys still being answered: how many more requests with
     * the key are told so, and the `Retry-After` they get.
     *
     * @var array<string, array{times: int, retryAfter: int}>
     */
    public array $inFlight = [];

    /**
     * The first answer to each key, by credential and key.
     *
     * @var array<string, array{fingerprint: string, status: int, body: string, headers: array<string, string>}>
     */
    public array $replays = [];

    /**
     * Security scheme of each credential a test registered; others go by
     * their prefix.
     *
     * @var array<string, string>
     */
    public array $credentials = [];
}
