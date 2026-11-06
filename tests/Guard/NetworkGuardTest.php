<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Guard;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\NetworkGuard;

/**
 * The test bootstrap keeps every suite off the network beyond loopback.
 */
final class NetworkGuardTest extends TestCase
{
    public function testAnHttpClientCannotReachARealHost(): void
    {
        try {
            (new Client())->get('https://api.pixonaut.app/v1/templates', ['connect_timeout' => 5]);
            self::fail('The request left the machine.');
        } catch (ConnectException $e) {
            self::assertMatchesRegularExpression('/127\.0\.0\.1.{1,10}\b1\b/', $e->getMessage());
        }
    }

    public function testTheStreamWrapperCannotReachARealHost(): void
    {
        self::assertFalse(@file_get_contents('https://api.pixonaut.app/v1/templates'));
    }

    public function testLoopbackIsReachedDirectly(): void
    {
        self::assertSame(NetworkGuard::LOOPBACK, getenv('NO_PROXY'));
        self::assertSame(NetworkGuard::DEAD_PROXY, getenv('HTTPS_PROXY'));
    }
}
