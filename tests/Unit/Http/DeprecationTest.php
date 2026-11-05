<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\Deprecation;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class DeprecationTest extends TestCase
{
    private const DEPRECATED_AT = 1_688_169_599;

    private const SUNSET = 'Wed, 31 Dec 2025 23:59:59 GMT';

    public function testANoticeBecomesAValueAListenerCallAndAWarning(): void
    {
        $harness = new Harness();
        $harness->answer(200, '{}', ['Deprecation' => '@' . self::DEPRECATED_AT, 'Sunset' => self::SUNSET]);

        $result = $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        $deprecation = $result->deprecation;
        self::assertInstanceOf(Deprecation::class, $deprecation);
        self::assertSame('designs.get', $deprecation->operation);
        self::assertSame(self::DEPRECATED_AT, $deprecation->deprecated?->getTimestamp());
        self::assertSame(self::SUNSET, $deprecation->sunsetHeader);
        self::assertSame('2025-12-31T23:59:59+00:00', $deprecation->sunset?->format(DATE_ATOM));
        self::assertSame([$deprecation], $harness->listener->deprecations);
        $warnings = $harness->logger->at('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('designs.get', $warnings[0]['message']);
    }

    public function testNoNoticeWithoutTheHeader(): void
    {
        $harness = new Harness();
        $harness->answer(200);

        self::assertNull($harness->pixonaut->call('designs.get', ['id' => 'd_1'])->deprecation);
        self::assertSame([], $harness->listener->deprecations);
        self::assertSame([], $harness->logger->records);
    }

    public function testAnUnreadableNoticeIsKeptRaw(): void
    {
        $deprecation = Deprecation::fromHeaders('designs.get', 'true', 'someday');

        self::assertNull($deprecation->deprecated);
        self::assertNull($deprecation->sunset);
        self::assertSame('true', $deprecation->header);
    }

    public function testANoticeOnARefusalStillReachesTheListener(): void
    {
        $harness = new Harness();
        $harness->answer(404, '{"error":"not_found"}', ['Deprecation' => '@' . self::DEPRECATED_AT]);

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
        } catch (ApiException) {
        }

        self::assertCount(1, $harness->listener->deprecations);
    }
}
