<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeServer;
use Pixonaut\Sdk\Tests\Fake\Fault;
use RuntimeException;

/**
 * The quick-start's steps, each running its example against the served
 * fake and checking what it did. A step throws when the example does not
 * do what the documentation says. `ExamplesTest` runs them over the
 * package; `tests/Prefixed/check.php` over a prefixed copy of it.
 */
final class QuickStart
{
    /** The code a merchant comes back from Pixonaut with. */
    private const CONNECT_CODE = 'code_1';

    private const NO_CONTENT = 204;

    private const UNAUTHORIZED = 401;

    /** What `rateLimited()` asks the example to wait. */
    private const RETRY_AFTER_SECONDS = 7;

    /** An hour: a delivery signed this long ago is out of the tolerance. */
    private const STALE_SECONDS = 3600;

    /** The private file mode `connect.php` stores the keys with. */
    private const KEYS_FILE_MODE = 0600;

    private const PERMISSION_BITS = 0777;

    private const VECTORS = __DIR__ . '/../../spec/webhook-signature-vectors.json';

    /** The design the first signature vector's event names. */
    private const VECTOR_DESIGN_ID = '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c';

    public function __construct(
        private readonly Examples $examples,
        private readonly FakeServer $server,
    ) {}

    /**
     * Every step, in the quick-start's order.
     */
    public function all(): void
    {
        $this->connect();
        $this->templates();
        $this->commit();
        $this->errors();
        $this->webhook();
        $this->assertNoViolations();
    }

    public function connect(): void
    {
        self::same("Connected to Acme.\n", $this->examples->run('connect.php', [self::CONNECT_CODE]), 'connect.php');
        $keys = $this->keys();
        self::check(str_starts_with($keys['integrationKey'], 'ik_'), 'connect.php stored no integration key.');
        self::check(str_starts_with($keys['webhookSecret'], 'whsec_'), 'connect.php stored no webhook secret.');
        self::check((fileperms($this->examples->keysFile) & self::PERMISSION_BITS) === self::KEYS_FILE_MODE, 'connect.php left the keys readable by others.');
    }

    public function templates(): void
    {
        $printed = $this->examples->run('templates.php');

        self::matches('/^\S+@\d+: .+\nRead .+, version \d+\.\n$/', $printed, 'templates.php');
        self::same(['templates.list', 'templates.get'], array_slice($this->operations(), -2), 'templates.php calls');
    }

    public function commit(): void
    {
        $this->server->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::lostAnswer(), 'designs.commit'));

        self::matches('/^Committed design \S+\.\n$/', $this->examples->run('commit.php'), 'commit.php');
        $commits = array_values(array_filter($this->server->state()->log, static fn(array $entry): bool => $entry['operation'] === 'designs.commit'));
        self::same(2, count($commits), 'commit.php sends');
        self::check($commits[1]['replayed'], 'commit.php\'s second send was not answered as a replay.');
    }

    public function errors(): void
    {
        self::matches('/^Read .+\.\n$/', $this->examples->run('errors.php'), 'errors.php');

        $this->server->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::rateLimited(self::RETRY_AFTER_SECONDS), 'templates.get'));
        self::same("Try again in 7 seconds (rate_limited).\n", $this->examples->run('errors.php'), 'errors.php when rate limited');

        $this->server->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::transportFailure(), 'templates.get'));
        self::same("Try again in 30 seconds (transport_failed).\n", $this->examples->run('errors.php'), 'errors.php when out of reach');
    }

    public function webhook(): void
    {
        $secret = $this->keys()['webhookSecret'];
        /** @var array{valid: list<array{body: string}>} $vectors */
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, flags: JSON_THROW_ON_ERROR);
        $committed = $vectors['valid'][0]['body'];
        $ping = $vectors['valid'][1]['body'];
        $unknown = '{"id":"evt_new","type":"something.added_later","payload":{"a":1},"createdAt":1791892800000}';

        $this->examples->serveWebhook(static function (WebhookEndpoint $endpoint) use ($secret, $committed, $ping, $unknown): void {
            self::same(self::NO_CONTENT, $endpoint->deliver($committed, $secret, time()), 'a signed delivery: ' . $endpoint->log());
            self::same(self::NO_CONTENT, $endpoint->deliver($ping, $secret, time()), 'a signed ping');
            self::same(self::NO_CONTENT, $endpoint->deliver($unknown, $secret, time()), 'a signed delivery of an unknown type');
            self::same(self::UNAUTHORIZED, $endpoint->deliver($committed, 'another-secret', time()), 'a delivery signed with another secret');
            self::same(self::UNAUTHORIZED, $endpoint->deliver($committed, $secret, time() - self::STALE_SECONDS), 'a stale delivery');
            self::same(self::UNAUTHORIZED, $endpoint->post($committed, []), 'an unsigned delivery');
            self::check(str_contains($endpoint->log(), 'Design ' . self::VECTOR_DESIGN_ID . ' committed.'), 'webhook.php did not act on design.committed: ' . $endpoint->log());
        });
    }

    /**
     * Every request the examples sent was one the spec accepts.
     */
    public function assertNoViolations(): void
    {
        self::same([], $this->server->state()->violations, 'violations of the spec');
    }

    /**
     * What `connect.php` stored.
     *
     * @return array{integrationKey: string, webhookSecret: string}
     */
    public function keys(): array
    {
        $keys = json_decode((string) file_get_contents($this->examples->keysFile), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($keys) || !is_string($keys['integrationKey'] ?? null) || !is_string($keys['webhookSecret'] ?? null)) {
            throw new RuntimeException('connect.php stored no keys.');
        }

        return ['integrationKey' => $keys['integrationKey'], 'webhookSecret' => $keys['webhookSecret']];
    }

    /**
     * @return list<string>
     */
    private function operations(): array
    {
        return array_column($this->server->state()->log, 'operation');
    }

    private static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf("%s: expected %s, got %s", $what, var_export($expected, true), var_export($actual, true)));
        }
    }

    private static function matches(string $pattern, string $actual, string $what): void
    {
        self::check(preg_match($pattern, $actual) === 1, sprintf('%s printed %s', $what, var_export($actual, true)));
    }

    private static function check(bool $holds, string $failure): void
    {
        if (!$holds) {
            throw new RuntimeException($failure);
        }
    }
}
