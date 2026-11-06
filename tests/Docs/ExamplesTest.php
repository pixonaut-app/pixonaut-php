<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\Fake\FakeServer;

/**
 * The quick-start's examples, run as written against the fake served on
 * loopback: what the documentation shows is what works. Each step throws
 * when its example does not do what the quick-start says.
 */
final class ExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../docs/examples';

    private const AUTOLOAD = __DIR__ . '/../../vendor/autoload.php';

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
        self::quickStart()->assertNoViolations();
    }

    public function testConnectingStoresTheKeys(): void
    {
        self::quickStart()->connect();
        $this->addToAssertionCount(1);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testTemplatesAreListedAndOneIsRead(): void
    {
        self::quickStart()->templates();
        $this->addToAssertionCount(1);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testACommitWhoseAnswerIsLostIsSentAgainAndMadeOnce(): void
    {
        self::quickStart()->commit();
        $this->addToAssertionCount(1);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testARetryableErrorSaysWhenToTryAgain(): void
    {
        self::quickStart()->errors();
        $this->addToAssertionCount(1);
    }

    #[Depends('testConnectingStoresTheKeys')]
    public function testTheWebhookEndpointActsOnlyOnSignedDeliveries(): void
    {
        self::quickStart()->webhook();
        $this->addToAssertionCount(1);
    }

    private static function quickStart(): QuickStart
    {
        if (self::$examples === null || self::$server === null) {
            throw new \LogicException('The fake is not running.');
        }

        return new QuickStart(self::$examples, self::$server);
    }
}
