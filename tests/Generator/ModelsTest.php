<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Model\Omitted;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;

final class ModelsTest extends TestCase
{
    use GeneratesCode;

    private const ORDER = [
        'type' => 'object',
        'description' => 'An order.',
        'required' => ['id', 'note', 'quantity'],
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'The order\'s id.'],
            'note' => ['type' => ['string', 'null']],
            'quantity' => ['type' => 'integer'],
            'price' => ['type' => 'number'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'lines' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'required' => ['sku'], 'properties' => ['sku' => ['type' => 'string']]],
            ],
            'meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'extra' => ['type' => 'object'],
            'rgb' => ['type' => 'array', 'prefixItems' => [['type' => 'number'], ['type' => 'number'], ['type' => 'number']], 'items' => false],
            'status' => ['type' => 'string', 'x-extensible-enum' => ['submitted', 'shipped']],
            'kind' => ['type' => 'string', 'enum' => ['print', 'sample']],
        ],
    ];

    public function testTypeArraysWithNullBecomeNullableTypes(): void
    {
        $namespace = $this->load(self::spec(['Order' => self::ORDER]));
        $class = $namespace . '\\Model\\Order';

        $order = Calls::fromArray($class, ['id' => 'o_1', 'note' => null, 'quantity' => 2]);
        self::assertNull(Calls::get($order, 'note'));
        self::assertSame('kept', Calls::get(Calls::fromArray($class, ['id' => 'o_1', 'note' => 'kept', 'quantity' => 2]), 'note'));

        $note = (new \ReflectionProperty(Calls::class($class), 'note'))->getType();
        self::assertSame('?string', (string) $note);
        self::assertSame('string', (string) (new \ReflectionProperty(Calls::class($class), 'id'))->getType());
    }

    public function testRequiredFieldsMustBePresentAndOptionalOnesMayBeLeftOut(): void
    {
        $class = $this->load(self::spec(['Order' => self::ORDER])) . '\\Model\\Order';

        $order = Calls::fromArray($class, ['id' => 'o_1', 'note' => null, 'quantity' => 2]);
        self::assertNull(Calls::get($order, 'price'));
        self::assertNull(Calls::get($order, 'tags'));

        $this->expectExceptionObject(new UnexpectedResponse('$', 'missing required field "note"'));
        Calls::fromArray($class, ['id' => 'o_1', 'quantity' => 2]);
    }

    public function testDecodesNestedValuesAndNamesTheJsonPathOfAWrongType(): void
    {
        $namespace = $this->load(self::spec(['Order' => self::ORDER]));
        $class = $namespace . '\\Model\\Order';

        $order = Calls::fromArray($class, [
            'id' => 'o_1',
            'note' => null,
            'quantity' => 2.0,
            'price' => 3,
            'tags' => ['a', 'b'],
            'lines' => [['sku' => 'mug'], ['sku' => 'poster']],
            'meta' => ['width' => 3],
            'extra' => ['anything' => [1, 2]],
            'rgb' => [0.5, 1, 0],
        ]);
        self::assertSame(2, Calls::get($order, 'quantity'));
        self::assertSame(3.0, Calls::get($order, 'price'));
        self::assertSame(['a', 'b'], Calls::get($order, 'tags'));
        $lines = Calls::get($order, 'lines');
        self::assertIsArray($lines);
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\OrderLinesItem'), $lines[1]);
        self::assertSame('poster', Calls::get($lines[1], 'sku'));
        self::assertSame(['width' => 3], Calls::get($order, 'meta'));
        self::assertSame([0.5, 1.0, 0.0], Calls::get($order, 'rgb'));

        try {
            Calls::fromArray($class, ['id' => 'o_1', 'note' => null, 'quantity' => 2, 'lines' => [['sku' => 'mug'], ['sku' => 7]]]);
            self::fail('A wrong type decoded.');
        } catch (UnexpectedResponse $e) {
            self::assertSame('$.lines[1].sku', $e->jsonPath);
            self::assertSame('expected string, got integer', $e->problem);
        }
    }

    public function testKeepsFieldsTheModelDoesNotKnow(): void
    {
        $class = $this->load(self::spec(['Order' => self::ORDER])) . '\\Model\\Order';

        $data = ['id' => 'o_1', 'note' => null, 'quantity' => 2, 'addedLater' => ['x' => 1]];
        $order = Calls::fromArray($class, $data);

        self::assertSame($data, $order->raw());
        self::assertSame(['id' => 'o_1', 'note' => null, 'quantity' => 2], $order->toArray());
    }

    public function testEnumsBecomeConstantsAndTheFieldStaysAString(): void
    {
        $namespace = $this->load(self::spec(['Order' => self::ORDER]));

        self::assertSame('submitted', Calls::constant($namespace . '\\Enum\\OrderStatus', 'SUBMITTED'));
        self::assertSame(['print', 'sample'], Calls::constant($namespace . '\\Enum\\OrderKind', 'KNOWN'));

        $order = Calls::fromArray($namespace . '\\Model\\Order', ['id' => 'o_1', 'note' => null, 'quantity' => 1, 'status' => 'returned', 'kind' => 'gift']);
        self::assertSame('returned', Calls::get($order, 'status'));
        self::assertSame('gift', Calls::get($order, 'kind'));
    }

    public function testARequestModelSendsNullOnlyForAFieldSetToNull(): void
    {
        $namespace = $this->load(self::spec([], [
            '/v1/orders/{id}' => self::operation('orders.update', [
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['reason'],
                    'properties' => [
                        'reason' => ['type' => 'string'],
                        'note' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => 'integer'],
                        'meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ],
                ]]]],
            ], 'patch'),
        ]));
        $class = $namespace . '\\Model\\OrdersUpdateRequest';

        self::assertSame(['reason' => 'typo'], Calls::construct($class, ['reason' => 'typo'])->toArray());
        self::assertSame(['reason' => 'typo', 'note' => null], Calls::construct($class, ['reason' => 'typo', 'note' => null])->toArray());
        self::assertSame(
            ['reason' => 'typo', 'note' => 'n', 'quantity' => 3],
            Calls::construct($class, ['reason' => 'typo', 'note' => 'n', 'quantity' => 3])->toArray(),
        );
        self::assertSame(Omitted::Field, Calls::get(Calls::construct($class, ['reason' => 'typo']), 'note'));
        self::assertEquals(
            '{"reason":"typo","meta":{}}',
            json_encode(Calls::construct($class, ['reason' => 'typo', 'meta' => []])->toArray()),
        );

        $decoded = Calls::fromArray($class, ['reason' => 'typo', 'note' => null]);
        self::assertSame(['reason' => 'typo', 'note' => null], $decoded->toArray());
        self::assertSame(['reason' => 'typo'], Calls::fromArray($class, ['reason' => 'typo'])->toArray());
    }

    public function testAnEmptyObjectStaysAnObjectBelowListsAndMaps(): void
    {
        $namespace = $this->load(self::spec([], [
            '/v1/things' => self::operation('things.create', [
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'names' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
                        'parts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]],
                        'values' => ['type' => 'object', 'additionalProperties' => ['anyOf' => [
                            ['type' => 'object', 'additionalProperties' => new \stdClass()],
                            ['type' => 'array', 'items' => ['type' => 'string']],
                        ]]],
                    ],
                ]]]],
            ]),
        ]));

        $body = Calls::fromArray($namespace . '\\Model\\ThingsCreateRequest', [
            'names' => ['red' => []],
            'parts' => [[]],
            'values' => ['layer' => [], 'tags' => ['a']],
        ]);

        self::assertSame(
            '{"names":{"red":{}},"parts":[{}],"values":{"layer":{},"tags":["a"]}}',
            json_encode($body->toArray()),
        );
    }

    public function testAllOfPartsAddUpToOneModel(): void
    {
        $namespace = $this->load(self::spec(['Base' => [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string']],
        ]], [
            '/v1/proofs' => self::operation('proofs.create', [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['allOf' => [
                    ['$ref' => '#/components/schemas/Base'],
                    ['type' => 'object', 'required' => ['size'], 'properties' => ['size' => ['type' => 'integer']]],
                ]]]]],
            ]),
        ]));
        $class = $namespace . '\\Model\\ProofsCreateRequest';

        self::assertSame(['id' => 'p', 'size' => 3], Calls::fromArray($class, ['id' => 'p', 'size' => 3])->toArray());
        $this->expectExceptionObject(new UnexpectedResponse('$', 'missing required field "size"'));
        Calls::fromArray($class, ['id' => 'p']);
    }

    public function testNestedModelsOfARequestAreRequestModelsToo(): void
    {
        $namespace = $this->load(self::spec([], [
            '/v1/orders' => self::operation('orders.create', [
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'print' => ['type' => 'object', 'properties' => ['note' => ['type' => ['string', 'null']]]],
                    ],
                ]]]],
            ]),
        ]));

        $print = Calls::construct($namespace . '\\Model\\OrdersCreateRequestPrint', []);
        self::assertSame(Omitted::Field, Calls::get($print, 'note'));
        self::assertSame(
            '{"print":{}}',
            json_encode(Calls::construct($namespace . '\\Model\\OrdersCreateRequest', ['print' => $print])->toArray()),
        );
    }
}
