<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\Binary;
use Pixonaut\Sdk\Tests\Contract\Support\Arguments;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeServer;
use Pixonaut\Sdk\Tests\Fake\Fault;
use Pixonaut\Sdk\Tests\Fake\Spec;
use ReflectionMethod;

/**
 * The SDK over a real PSR-18 client and a socket, against the fake served
 * by PHP's built-in server on loopback: what an in-memory client cannot
 * show, such as header names in another case, an empty answer and a
 * binary one.
 */
final class WireTest extends TestCase
{
    private static ?FakeServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = FakeServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function tearDown(): void
    {
        self::assertSame([], self::server()->state()->violations);
    }

    public function testHeadersAreReadWhateverTheCaseTheyArriveIn(): void
    {
        $client = self::client();

        $first = $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_wire'], idempotencyKey: 'wire-1');
        $again = $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_wire'], idempotencyKey: 'wire-1');

        self::assertSame(201, $first->status);
        self::assertFalse($first->replayed());
        self::assertTrue($again->replayed());
        self::assertStringStartsWith('req_fake_', (string) $again->requestId());
        self::assertSame($first->body, $again->body);
    }

    public function testAnEmptyAnswerIsRead(): void
    {
        self::client()->pixonaut->connect()->delete();

        $log = self::server()->state()->log;
        self::assertSame(['connectedStore.delete', 204], [$log[count($log) - 1]['operation'], $log[count($log) - 1]['status']]);
    }

    public function testABinaryAnswerKeepsItsBytes(): void
    {
        $templates = self::client()->pixonaut->templates();
        $arguments = Arguments::forMethod(Spec::pinned()->operation('templates.mockup.get'), new ReflectionMethod($templates, 'getMockup'));

        $image = (new ReflectionMethod($templates, 'getMockup'))->invokeArgs($templates, $arguments);

        self::assertInstanceOf(Binary::class, $image);
        self::assertSame('image/png', $image->contentType);
        self::assertSame(FakePixonaut::binary('templates.mockup.get', 'image/png'), $image->bytes);
    }

    public function testALostAnswerIsAFailedTransferAndTheResendIsReplayed(): void
    {
        $client = self::client();
        self::server()->script(static function (FakePixonaut $fake): void {
            $fake->fault(Fault::lostAnswer(), 'designs.commit');
        });
        $prepared = $client->pixonaut->prepare('designs.commit', body: ['proofToken' => 'proof_lost'], idempotencyKey: 'wire-lost');

        try {
            $client->pixonaut->send($prepared);
            self::fail('The lost answer was read.');
        } catch (ApiException $e) {
            self::assertSame([ApiException::TRANSPORT_FAILED, true], [$e->errorCode, $e->retryable]);
        }
        $again = $client->pixonaut->send($prepared);

        self::assertTrue($again->replayed());
        $commits = array_values(array_filter(self::server()->state()->log, static fn(array $entry): bool => $entry['target'] === '/v1/designs' && $entry['operation'] === 'designs.commit'));
        self::assertSame([false, true], array_column(array_slice($commits, -2), 'replayed'));
    }

    private static function client(): ContractClient
    {
        return new ContractClient(new Client(), self::server()->baseUrl());
    }

    private static function server(): FakeServer
    {
        return self::$server ?? throw new \LogicException('The fake server is not running.');
    }
}
