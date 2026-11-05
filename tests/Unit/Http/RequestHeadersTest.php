<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class RequestHeadersTest extends TestCase
{
    public function testSendsAcceptAndUserAgentWithoutABody(): void
    {
        $harness = new Harness();
        $harness->answer(200, '{"id":"d_1"}');

        $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        $sent = $harness->lastSent();
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://api.test/v1/designs/d_1', (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame('test-host/1.0 pixonaut-php/' . Pixonaut::VERSION, $sent->getHeaderLine('User-Agent'));
        self::assertFalse($sent->hasHeader('Content-Type'));
        self::assertFalse($sent->hasHeader('Idempotency-Key'));
        self::assertSame('', (string) $sent->getBody());
    }

    public function testAcceptIsWhatTheOperationAnswers(): void
    {
        $harness = new Harness();
        $harness->answer(200, 'png');

        $harness->pixonaut->call('designs.proof.get', ['id' => 'd_1']);

        self::assertSame('image/png', $harness->lastSent()->getHeaderLine('Accept'));
    }

    public function testSendsContentTypeOnlyWithABody(): void
    {
        $harness = new Harness();
        $harness->answer(200);

        $harness->pixonaut->call('designs.update', ['id' => 'd_1'], [], ['name' => 'Ä/b']);

        $sent = $harness->lastSent();
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"Ä/b","touched":true}', (string) $sent->getBody());
        self::assertFalse($sent->hasHeader('Idempotency-Key'));
    }

    public function testSendsIdempotencyKeyOnlyOnIdempotentOperations(): void
    {
        $harness = new Harness();
        $harness->answer(201);

        $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], [], 'key-1');

        self::assertSame('key-1', $harness->lastSent()->getHeaderLine('Idempotency-Key'));
    }

    public function testSendsTheIntegrationKeyAsBearer(): void
    {
        $harness = new Harness();
        $harness->answer(200);

        $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        self::assertSame('Bearer ' . Harness::INTEGRATION_KEY, $harness->lastSent()->getHeaderLine('Authorization'));
        self::assertFalse($harness->lastSent()->hasHeader('Origin'));
    }

    public function testChoosesTheCredentialByConfiguredPreference(): void
    {
        $credentials = new StaticCredentials(integrationKey: 'ik_1', publishableKey: 'pk_1');

        $default = new Harness($credentials, origin: 'https://shop.example');
        $default->answer(200);
        $default->pixonaut->call('designs.get', ['id' => 'd_1']);
        self::assertSame('Bearer ik_1', $default->lastSent()->getHeaderLine('Authorization'));

        $publishableFirst = new Harness($credentials, origin: 'https://shop.example', preference: [Credential::PublishableKey]);
        $publishableFirst->answer(200);
        $publishableFirst->pixonaut->call('designs.get', ['id' => 'd_1']);
        self::assertSame('Bearer pk_1', $publishableFirst->lastSent()->getHeaderLine('Authorization'));
    }

    public function testFallsBackToAnAcceptedCredentialThePreferenceLeavesOut(): void
    {
        $harness = new Harness(preference: [Credential::SecretKey]);
        $harness->answer(200);

        $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        self::assertSame('Bearer ' . Harness::INTEGRATION_KEY, $harness->lastSent()->getHeaderLine('Authorization'));
    }

    public function testSkipsACredentialTheOperationDoesNotAccept(): void
    {
        $harness = new Harness(new StaticCredentials(integrationKey: 'ik_1', secretKey: 'sk_1'));
        $harness->answer(200);

        $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        self::assertSame('Bearer ik_1', $harness->lastSent()->getHeaderLine('Authorization'));
    }

    public function testSendsNoCredentialToAnAnonymousOperationWhenNoneIsHeld(): void
    {
        $harness = new Harness(new StaticCredentials());
        $harness->answer(200, '[]');

        $harness->pixonaut->call('templates.list');

        self::assertFalse($harness->lastSent()->hasHeader('Authorization'));
    }

    public function testThrowsNotConnectedAndSendsNothingWhenNoCredentialIsHeld(): void
    {
        $harness = new Harness(new StaticCredentials(integrationKey: '', publishableKey: 'pk_1'));
        $harness->answer(200);

        try {
            $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], []);
            self::fail('Expected not_connected.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::NOT_CONNECTED, $e->errorCode);
            self::assertSame(0, $e->status);
            self::assertFalse($e->retryable);
        }
        self::assertSame([], $harness->sent());
        self::assertSame(['body designs.commit'], $harness->listener->calls);
    }

    public function testSendsTheOriginWithAPublishableKey(): void
    {
        $harness = new Harness(new StaticCredentials(publishableKey: 'pk_1'), origin: 'https://shop.example/');
        $harness->answer(200, '[]');

        $harness->pixonaut->call('templates.list');

        self::assertSame('Bearer pk_1', $harness->lastSent()->getHeaderLine('Authorization'));
        self::assertSame('https://shop.example', $harness->lastSent()->getHeaderLine('Origin'));
    }

    public function testReadsTheCredentialAtEverySend(): void
    {
        $credentials = new class implements \Pixonaut\Sdk\Credentials\CredentialProvider {
            public string $key = 'ik_old';

            public function secret(Credential $credential): ?string
            {
                return $credential === Credential::IntegrationKey ? $this->key : null;
            }
        };
        $harness = new Harness($credentials);
        $harness->answer(200);
        $harness->answer(200);

        $request = $harness->pixonaut->prepare('designs.get', ['id' => 'd_1']);
        $harness->pixonaut->send($request);
        $credentials->key = 'ik_new';
        $harness->pixonaut->send($request);

        self::assertSame('Bearer ik_old', $harness->sent()[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer ik_new', $harness->sent()[1]->getHeaderLine('Authorization'));
    }
}
