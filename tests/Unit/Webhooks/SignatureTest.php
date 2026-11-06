<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Webhooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Webhooks\Signature;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"id":"evt_1","type":"webhook.ping","payload":{"endpointId":"wh_1"},"createdAt":1791892800000}';

    private const NOW = 1791892800;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validVectors(): array
    {
        return self::named(Vectors::signatures()['valid']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidVectors(): array
    {
        return self::named(Vectors::signatures()['invalid']);
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('validVectors')]
    public function testEveryValidVectorPasses(array $vector): void
    {
        self::assertTrue(self::verifyVector($vector));
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('invalidVectors')]
    public function testEveryInvalidVectorFails(array $vector): void
    {
        self::assertFalse(self::verifyVector($vector));
    }

    public function testTheVectorsUseThisReleasesSchemeAndTolerance(): void
    {
        $vectors = Vectors::signatures();

        self::assertSame(Signature::SCHEME, $vectors['scheme']);
        self::assertSame(Signature::TOLERANCE_SECONDS, $vectors['toleranceSeconds']);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function window(): array
    {
        return [
            'sent the tolerance ago' => [-Signature::TOLERANCE_SECONDS, true],
            'sent a second more than the tolerance ago' => [-Signature::TOLERANCE_SECONDS - 1, false],
            'stamped the tolerance ahead' => [Signature::TOLERANCE_SECONDS, true],
            'stamped a second more than the tolerance ahead' => [Signature::TOLERANCE_SECONDS + 1, false],
        ];
    }

    #[DataProvider('window')]
    public function testTheWindowHoldsTheToleranceEitherWay(int $offset, bool $passes): void
    {
        $timestamp = (string) (self::NOW + $offset);

        self::assertSame($passes, Signature::verify(self::SECRET, $timestamp, self::header(self::SECRET, $timestamp), self::BODY, self::NOW));
    }

    public function testAnyMatchingSignatureOfSeveralPassesWhileASecretIsRotated(): void
    {
        $timestamp = (string) self::NOW;
        $old = self::header('whsec_old', $timestamp);
        $new = self::header(self::SECRET, $timestamp);

        self::assertTrue(Signature::verify(self::SECRET, $timestamp, $old . ',' . $new, self::BODY, self::NOW));
        self::assertTrue(Signature::verify(self::SECRET, $timestamp, $new . ', ' . $old, self::BODY, self::NOW));
        self::assertFalse(Signature::verify('whsec_other', $timestamp, $old . ',' . $new, self::BODY, self::NOW));
    }

    public function testASignatureOfAnotherSchemeIsIgnored(): void
    {
        $timestamp = (string) self::NOW;
        $digest = substr(self::header(self::SECRET, $timestamp), strlen(Signature::SCHEME) + 1);

        self::assertFalse(Signature::verify(self::SECRET, $timestamp, 'v0=' . $digest, self::BODY, self::NOW));
        self::assertTrue(Signature::verify(self::SECRET, $timestamp, 'v0=' . $digest . ',v1=' . $digest, self::BODY, self::NOW));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'an empty secret' => ['', (string) self::NOW],
            'a timestamp with a sign' => [self::SECRET, '+' . self::NOW],
            'a timestamp with a fraction' => [self::SECRET, self::NOW . '.0'],
            'an empty timestamp' => [self::SECRET, ''],
            'a timestamp with spaces' => [self::SECRET, ' ' . self::NOW],
        ];
    }

    #[DataProvider('refused')]
    public function testAnEmptySecretOrATimestampNotAllDigitsFails(string $secret, string $timestamp): void
    {
        // Signed over exactly what is checked, so only the rule refuses it.
        $header = Signature::SCHEME . '=' . hash_hmac('sha256', $timestamp . '.' . self::BODY, $secret);

        self::assertFalse(Signature::verify($secret, $timestamp, $header, self::BODY, self::NOW));
    }

    public function testTheHeaderNamesAreTheOnesPixonautSends(): void
    {
        self::assertSame('x-pixonaut-timestamp', Signature::TIMESTAMP_HEADER);
        self::assertSame('x-pixonaut-signature', Signature::SIGNATURE_HEADER);
    }

    /**
     * @param array<string, mixed> $vector
     */
    private static function verifyVector(array $vector): bool
    {
        /** @var array<string, string> $headers */
        $headers = $vector['headers'];
        $now = $vector['now'] ?? Vectors::signatures()['now'];
        self::assertIsInt($now);
        self::assertIsString($vector['secret']);
        self::assertIsString($vector['body']);

        return Signature::verify(
            $vector['secret'],
            $headers[Signature::TIMESTAMP_HEADER] ?? '',
            $headers[Signature::SIGNATURE_HEADER] ?? '',
            $vector['body'],
            $now,
        );
    }

    private static function header(string $secret, string $timestamp): string
    {
        return Signature::SCHEME . '=' . hash_hmac('sha256', $timestamp . '.' . self::BODY, $secret);
    }

    /**
     * @param list<array<string, mixed>> $vectors
     *
     * @return array<string, array{array<string, mixed>}>
     */
    private static function named(array $vectors): array
    {
        $named = [];
        foreach ($vectors as $vector) {
            self::assertIsString($vector['name']);
            $named[$vector['name']] = [$vector];
        }

        return $named;
    }
}
