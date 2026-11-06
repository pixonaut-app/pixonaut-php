<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Guard;

use InvalidArgumentException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\Live\LiveTarget;
use Pixonaut\Sdk\Tests\Live\LoopbackOnlyClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The live smoke skips without its variables and never reaches a host
 * other than loopback.
 */
final class LiveSmokeGuardTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testWithNothingSetThereIsNoTarget(): void
    {
        self::assertNull(LiveTarget::fromEnvironment([]));
        self::assertNull(LiveTarget::fromEnvironment([LiveTarget::URL => 'http://127.0.0.1:3000']));
    }

    public function testTheSkipMessageNamesTheVariables(): void
    {
        foreach ([LiveTarget::URL, LiveTarget::SECRET_KEY, LiveTarget::INTEGRATION_KEY] as $name) {
            self::assertStringContainsString($name, LiveTarget::SKIPPED);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopback(): iterable
    {
        foreach (['http://127.0.0.1:3000', 'http://localhost:3000/', 'https://api.localhost', 'http://[::1]:3000', 'http://127.1.2.3'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('loopback')]
    public function testTakesALoopbackUrl(string $url): void
    {
        $target = LiveTarget::fromEnvironment([LiveTarget::URL => $url, LiveTarget::SECRET_KEY => 'sk_1', LiveTarget::INTEGRATION_KEY => 'ik_1']);

        self::assertSame(rtrim($url, '/'), $target?->baseUrl);
        self::assertSame(['sk_1', 'ik_1'], [$target->secretKey, $target->integrationKey]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function elsewhere(): iterable
    {
        foreach (['https://api.pixonaut.app', 'http://localhost.example.com', 'http://127.0.0.1.nip.io', 'http://10.0.0.5', 'http://[::2]', 'ftp://127.0.0.1', '127.0.0.1:3000'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('elsewhere')]
    public function testRefusesAnyOtherUrl(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(LiveTarget::URL . ' must name a loopback host');

        LiveTarget::fromEnvironment([LiveTarget::URL => $url, LiveTarget::SECRET_KEY => 'sk_1']);
    }

    public function testTheClientRefusesAnotherHostBeforeSending(): void
    {
        $inner = new class implements ClientInterface {
            public int $sent = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->sent;

                throw new \LogicException('Sent.');
            }
        };

        try {
            (new LoopbackOnlyClient($inner))->sendRequest(new Request('GET', 'https://api.pixonaut.app/v1/templates'));
            self::fail('The request was sent.');
        } catch (RequestExceptionInterface $e) {
            self::assertSame('The live smoke sends only to loopback, not api.pixonaut.app.', $e->getMessage());
        }
        self::assertSame(0, $inner->sent);
    }

    public function testTheSuiteSkipsWithNothingSetAndNamesTheVariables(): void
    {
        [$exit, $output] = self::runLiveSuite([]);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('Skipped: 5', $output);
        self::assertStringContainsString(LiveTarget::SKIPPED, $output);
    }

    public function testTheSuiteFailsForAnotherHost(): void
    {
        [$exit, $output] = self::runLiveSuite([LiveTarget::URL => 'https://api.pixonaut.app', LiveTarget::SECRET_KEY => 'sk_1']);

        self::assertNotSame(0, $exit, $output);
        self::assertStringContainsString(LiveTarget::URL . ' must name a loopback host', $output);
    }

    /**
     * @param array<string, string> $variables
     *
     * @return array{int, string}
     */
    private static function runLiveSuite(array $variables): array
    {
        $environment = getenv();
        foreach ([LiveTarget::URL, LiveTarget::SECRET_KEY, LiveTarget::INTEGRATION_KEY] as $name) {
            unset($environment[$name]);
        }
        $process = proc_open(
            [PHP_BINARY, 'vendor/bin/phpunit', '--testsuite', 'live', '--display-skipped', '--no-progress'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::ROOT,
            $variables + $environment,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
