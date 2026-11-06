<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeServer;
use Pixonaut\Sdk\Tests\Fake\Fault;

/**
 * The quick-start's examples, run as written against the fake served on
 * loopback: what the documentation shows is what works.
 */
final class ExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../docs/examples';

    private const AUTOLOAD = __DIR__ . '/../../vendor/autoload.php';

    /** The code a merchant comes back from Pixonaut with. */
    private const CONNECT_CODE = 'code_1';

    private const NO_CONTENT = 204;

    private const UNAUTHORIZED = 401;

    /** What `rateLimited()` asks the examples to wait. */
    private const RETRY_AFTER_SECONDS = 7;

    private static ?FakeServer $server = null;

    private static ?Examples $examples = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FakeServer::start();
        self::$examples = new Examples((string) realpath(self::EXAMPLES), (string) realpath(self::AUTOLOAD), self::$server->baseUrl());
    }

    public static function tearDownAfterClass(): void
    {
        self::$examples?->cleanUp();
        self::$examples = null;
        self::$server?->stop();
        self::$server = null;
    }

    protected function tearDown(): void
    {
        self::assertSame([], self::server()->state()->violations);
    }

    public function testConnectingStoresTheKeys(): void
    {
        $printed = self::examples()->run('connect.php', [self::CONNECT_CODE]);

        self::assertSame("Connected to Acme.\n", $printed);
        $keys = json_decode((string) file_get_contents(self::examples()->keysFile), true);
        self::assertIsArray($keys);
        self::assertStringStartsWith('ik_', (string) $keys['integrationKey']);
        self::assertStringStartsWith('whsec_', (string) $keys['webhookSecret']);
        self::assertSame(0600, fileperms(self::examples()->keysFile) & 0777);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testTemplatesAreListedAndOneIsRead(): void
    {
        $printed = self::examples()->run('templates.php');

        self::assertMatchesRegularExpression('/^\S+@\d+: .+\nRead .+, version \d+\.\n$/', $printed);
        self::assertSame(['templates.list', 'templates.get'], array_slice(self::operations(), -2));
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testACommitWhoseAnswerIsLostIsSentAgainAndMadeOnce(): void
    {
        self::server()->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::lostAnswer(), 'designs.commit'));

        $printed = self::examples()->run('commit.php');

        self::assertMatchesRegularExpression('/^Committed design \S+\.\n$/', $printed);
        $log = self::server()->state()->log;
        $commits = array_values(array_filter($log, static fn(array $entry): bool => $entry['operation'] === 'designs.commit'));
        self::assertCount(2, $commits);
        self::assertTrue($commits[1]['replayed']);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testARetryableErrorSaysWhenToTryAgain(): void
    {
        self::assertMatchesRegularExpression('/^Read .+\.\n$/', self::examples()->run('errors.php'));

        self::server()->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::rateLimited(self::RETRY_AFTER_SECONDS), 'templates.get'));
        self::assertSame("Try again in 7 seconds (rate_limited).\n", self::examples()->run('errors.php'));

        self::server()->script(static fn(FakePixonaut $fake) => $fake->fault(Fault::transportFailure(), 'templates.get'));
        self::assertSame("Try again in 30 seconds (transport_failed).\n", self::examples()->run('errors.php'));
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testTheWebhookEndpointActsOnlyOnSignedDeliveries(): void
    {
        $keys = json_decode((string) file_get_contents(self::examples()->keysFile), true);
        self::assertIsArray($keys);
        $secret = (string) $keys['webhookSecret'];
        $vectors = json_decode((string) file_get_contents(__DIR__ . '/../../spec/webhook-signature-vectors.json'), true);
        self::assertIsArray($vectors);
        $committed = (string) $vectors['valid'][0]['body'];
        $ping = (string) $vectors['valid'][1]['body'];
        $unknown = '{"id":"evt_new","type":"something.added_later","payload":{"a":1},"createdAt":1791892800000}';

        self::examples()->serveWebhook(static function (WebhookEndpoint $endpoint) use ($secret, $committed, $ping, $unknown): void {
            self::assertSame(self::NO_CONTENT, $endpoint->deliver($committed, $secret, time()), $endpoint->log());
            self::assertSame(self::NO_CONTENT, $endpoint->deliver($ping, $secret, time()));
            self::assertSame(self::NO_CONTENT, $endpoint->deliver($unknown, $secret, time()));
            self::assertSame(self::UNAUTHORIZED, $endpoint->deliver($committed, 'another-secret', time()));
            self::assertSame(self::UNAUTHORIZED, $endpoint->deliver($committed, $secret, time() - 3600));
            self::assertSame(self::UNAUTHORIZED, $endpoint->post($committed, []));

            self::assertStringContainsString('Design 6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c committed.', $endpoint->log());
        });
    }

    /**
     * @return list<string>
     */
    private static function operations(): array
    {
        return array_column(self::server()->state()->log, 'operation');
    }

    private static function server(): FakeServer
    {
        return self::$server ?? throw new \LogicException('The fake is not running.');
    }

    private static function examples(): Examples
    {
        return self::$examples ?? throw new \LogicException('The examples are not set up.');
    }
}
