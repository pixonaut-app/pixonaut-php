<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Webhooks;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\InvalidSignature;
use Pixonaut\Sdk\Generated\Model\WebhookPingEvent;
use Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\Sdk\Webhooks\Verifier;
use UnexpectedValueException;

final class VerifierTest extends TestCase
{
    public function testReturnsTheEventOfASignedDelivery(): void
    {
        $vector = self::vector('a ping');
        $verifier = new Verifier(self::string($vector['secret']), static fn(): int => Vectors::signatures()['now']);

        $event = $verifier->verify(self::request($vector));

        self::assertSame('webhook.ping', $event->type);
        self::assertInstanceOf(WebhookPingEvent::class, $event->typed());
    }

    public function testReadsABodyAnEarlierReaderLeftAtItsEnd(): void
    {
        $vector = self::vector('a ping');
        $request = self::request($vector);
        $request->getBody()->getContents();

        $event = (new Verifier(self::string($vector['secret']), static fn(): int => Vectors::signatures()['now']))->verify($request);

        self::assertSame('webhook.ping', $event->type);
    }

    public function testRefusesADeliveryWhoseSignatureDoesNotVerify(): void
    {
        $vector = self::vector('a ping');

        $this->expectException(InvalidSignature::class);
        (new Verifier('another secret', static fn(): int => Vectors::signatures()['now']))->verify(self::request($vector));
    }

    public function testUsesItsClock(): void
    {
        $vector = self::vector('a ping');
        $late = Vectors::signatures()['now'] + Signature::TOLERANCE_SECONDS + 1;

        $this->expectException(InvalidSignature::class);
        (new Verifier(self::string($vector['secret']), static fn(): int => $late))->verify(self::request($vector));
    }

    public function testRefusesADeliveryWithoutTheHeaders(): void
    {
        $vector = self::vector('a ping');
        $request = self::request($vector)->withoutHeader(Signature::SIGNATURE_HEADER);

        $this->expectException(InvalidSignature::class);
        (new Verifier(self::string($vector['secret']), static fn(): int => Vectors::signatures()['now']))->verify($request);
    }

    public function testASignedBodyThatIsNotAnEventIsRefusedAsSuch(): void
    {
        $secret = 'whsec_test';
        $now = 1791892800;
        $body = '{"hello":"world"}';
        $request = new ServerRequest('POST', '/webhook', [
            Signature::TIMESTAMP_HEADER => (string) $now,
            Signature::SIGNATURE_HEADER => Signature::SCHEME . '=' . hash_hmac('sha256', $now . '.' . $body, $secret),
        ], $body);

        $this->expectException(UnexpectedValueException::class);
        (new Verifier($secret, static fn(): int => $now))->verify($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function vector(string $name): array
    {
        foreach (Vectors::signatures()['valid'] as $vector) {
            if ($vector['name'] === $name) {
                return $vector;
            }
        }
        self::fail('The spec has no valid vector named ' . $name . '.');
    }

    /**
     * @param array<string, mixed> $vector
     */
    private static function request(array $vector): ServerRequest
    {
        /** @var array<string, string> $headers */
        $headers = $vector['headers'];

        return new ServerRequest('POST', '/webhook', $headers, self::string($vector['body']));
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
