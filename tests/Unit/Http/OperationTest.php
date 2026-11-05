<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Http\ArrayCatalogue;
use Pixonaut\Sdk\Http\Operation;

final class OperationTest extends TestCase
{
    public function testMatchesTargetsOfItsTemplate(): void
    {
        $operation = new Operation('designs.revisions.get', 'GET', '/v1/designs/{id}/revisions/{revision}');

        self::assertTrue($operation->matches('/v1/designs/d_1/revisions/3'));
        self::assertTrue($operation->matches('/v1/designs/d%2F1/revisions/3?v=2'));
        self::assertFalse($operation->matches('/v1/designs/d_1/revisions/'));
        self::assertFalse($operation->matches('/v1/designs/d_1/revisions/3/x'));
        self::assertFalse($operation->matches("/v1/designs/d_1/revisions/3\n"));
        self::assertFalse($operation->matches('/v1/designs/d_1/revisions/3#x'));
    }

    public function testTreatsTheTemplateLiterally(): void
    {
        $operation = new Operation('a.b', 'GET', '/v1/a.b+c');

        self::assertTrue($operation->matches('/v1/a.b+c'));
        self::assertFalse($operation->matches('/v1/aXbbc'));
    }

    public function testCatalogueRefusesADuplicateAndAnUnknownId(): void
    {
        $operation = new Operation('a', 'GET', '/a');

        self::assertSame($operation, (new ArrayCatalogue([$operation]))->operation('a'));
        try {
            (new ArrayCatalogue([]))->operation('a');
            self::fail('Expected an unknown operation.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new ArrayCatalogue([$operation, $operation]);
    }
}
