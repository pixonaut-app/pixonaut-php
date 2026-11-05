<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Pixonaut;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new Config();

        self::assertSame('https://api.pixonaut.app', $config->baseUrl);
        self::assertSame('pixonaut-php/' . Pixonaut::VERSION, $config->userAgent());
        self::assertSame(Config::DEFAULT_CREDENTIAL_PREFERENCE, $config->credentialPreference);
        self::assertNull($config->origin);
    }

    public function testTrimsTheTrailingSlash(): void
    {
        self::assertSame('http://localhost:8931', (new Config('http://localhost:8931/'))->baseUrl);
    }

    public function testPrependsTheHostsProductToken(): void
    {
        self::assertSame('my-plugin/2.1 pixonaut-php/' . Pixonaut::VERSION, (new Config(product: 'my-plugin/2.1'))->userAgent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBaseUrls(): iterable
    {
        yield 'relative' => ['/v1'];
        yield 'no scheme' => ['api.pixonaut.app'];
        yield 'ftp' => ['ftp://api.pixonaut.app'];
        yield 'query' => ['https://api.pixonaut.app?x=1'];
        yield 'credentials' => ['https://user:pw@api.pixonaut.app'];
    }

    #[DataProvider('badBaseUrls')]
    public function testRefusesABaseUrlThatIsNotAbsoluteHttp(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badOrigins(): iterable
    {
        yield 'path' => ['https://shop.example/shop'];
        yield 'no scheme' => ['shop.example'];
    }

    #[DataProvider('badOrigins')]
    public function testRefusesAnOriginThatIsNotOne(string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(origin: $origin);
    }

    public function testRefusesAProductTokenThatWouldBreakTheHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(product: "my-plugin/2.1\n");
    }

    public function testRefusesADuplicatedPreference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(credentialPreference: [Credential::SecretKey, Credential::SecretKey]);
    }
}
