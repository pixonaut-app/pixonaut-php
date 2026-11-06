<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\Generator\Support\JsonSchema;

/**
 * The validator the pinned-spec tests check request bodies with.
 */
final class JsonSchemaTest extends TestCase
{
    private const ALL_OF = ['allOf' => [
        ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['size' => ['type' => 'integer']]],
    ]];

    public function testChecksEveryPartOfAnAllOf(): void
    {
        $validator = new JsonSchema([]);

        self::assertSame([], $validator->validate(self::ALL_OF, (object) ['id' => 'p', 'size' => 3]));
        self::assertSame(
            ['$: missing id', '$.size: expected integer, got string'],
            $validator->validate(self::ALL_OF, (object) ['size' => 'big']),
        );
    }
}
