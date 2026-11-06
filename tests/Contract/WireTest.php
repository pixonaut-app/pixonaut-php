<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Http\Binary;
use Pixonaut\Sdk\Tests\Contract\Support\Arguments;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeServer;
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

    private static function client(): ContractClient
    {
        return new ContractClient(new Client(), self::server()->baseUrl());
    }

    private static function server(): FakeServer
    {
        return self::$server ?? throw new \LogicException('The fake server is not running.');
    }
}
