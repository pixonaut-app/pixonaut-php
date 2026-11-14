<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\GeneratorException;
use Pixonaut\Sdk\Model\UnknownVariant;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;

final class UnionsTest extends TestCase
{
    use GeneratesCode;

    private const STANDING = [
        'oneOf' => [
            ['type' => 'object', 'required' => ['state'], 'properties' => ['state' => ['type' => 'string', 'x-extensible-enum' => ['enabled', 'disabled']]]],
            ['type' => 'object', 'required' => ['state', 'reason'], 'properties' => ['state' => ['type' => 'string', 'enum' => ['failing']], 'reason' => ['type' => 'string']]],
        ],
    ];

    public function testOneOfWithoutADiscriminatorPicksTheVariantThatMatches(): void
    {
        $namespace = $this->load(self::spec(['Standing' => self::STANDING]));
        $decoder = $namespace . '\\Model\\Standing';

        self::assertInstanceOf(Calls::class($namespace . '\\Model\\StandingOption1'), Calls::decode($decoder, ['state' => 'enabled']));
        // Matches both: the variant whose state allows one value only says more.
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\StandingFailing'), Calls::decode($decoder, ['state' => 'failing', 'reason' => 'timeout']));

        $this->expectException(UnexpectedResponse::class);
        Calls::decode($decoder, ['reason' => 'timeout']);
    }

    public function testAVariantNamedByItsTagReportsItsOwnErrors(): void
    {
        $decoder = $this->load(self::spec(['Standing' => self::STANDING])) . '\\Model\\Standing';

        try {
            Calls::decode($decoder, ['state' => 'failing', 'reason' => 42]);
            self::fail('A failing standing with a wrong reason decoded.');
        } catch (UnexpectedResponse $e) {
            self::assertSame('$.reason', $e->jsonPath);
        }
    }

    public function testTagsOfReferencedComponentsTellTheVariantsApart(): void
    {
        $tagged = static fn(string $kind): array => [
            'type' => 'object',
            'required' => ['kind', 'id'],
            'properties' => ['kind' => ['type' => 'string', 'enum' => [$kind]], 'id' => ['type' => 'string']],
        ];
        $namespace = $this->load(self::spec([
            'Alpha' => $tagged('a'),
            'Beta' => $tagged('b'),
            'Either' => ['oneOf' => [['$ref' => '#/components/schemas/Alpha'], ['$ref' => '#/components/schemas/Beta']]],
        ]));

        self::assertInstanceOf(Calls::class($namespace . '\\Model\\Beta'), Calls::decode($namespace . '\\Model\\Either', ['kind' => 'b', 'id' => 'x']));
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\Alpha'), Calls::decode($namespace . '\\Model\\Either', ['kind' => 'a', 'id' => 'x']));
    }

    public function testOneOfRefusesAValueTwoEquallySpecificVariantsMatch(): void
    {
        $decoder = $this->load(self::spec(['Either' => ['oneOf' => [
            ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
        ]]])) . '\\Model\\Either';

        $this->expectExceptionObject(new UnexpectedResponse('$', 'matches more than one of the variants it may take'));
        Calls::decode($decoder, ['a' => 'x', 'b' => 'y']);
    }

    public function testAnyOfTakesTheFirstVariantThatMatches(): void
    {
        $namespace = $this->load(self::spec(['Either' => ['anyOf' => [
            ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string']]],
            ['type' => 'string'],
        ]]]));
        $decoder = $namespace . '\\Model\\Either';

        self::assertInstanceOf(Calls::class($namespace . '\\Model\\EitherOption1'), Calls::decode($decoder, ['a' => 'x', 'b' => 'y']));
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\EitherOption2'), Calls::decode($decoder, ['b' => 'y']));
        self::assertSame('plain', Calls::decode($decoder, 'plain'));
    }

    public function testADiscriminatorPicksTheVariantItNames(): void
    {
        $event = static fn(string $type): array => [
            'type' => 'object',
            'required' => ['type', 'id'],
            'properties' => ['type' => ['type' => 'string', 'enum' => [$type]], 'id' => ['type' => 'string']],
        ];
        $namespace = $this->load(self::spec(
            ['OrderCreated' => $event('order.created'), 'OrderCancelled' => $event('order.cancelled')],
            ['/v1/events' => self::operation('events.list', ['responses' => self::json200([
                'type' => 'object',
                'required' => ['events'],
                'properties' => ['events' => ['type' => 'array', 'items' => [
                    'oneOf' => [['$ref' => '#/components/schemas/OrderCreated'], ['$ref' => '#/components/schemas/OrderCancelled']],
                    'discriminator' => ['propertyName' => 'type', 'mapping' => [
                        'order.created' => '#/components/schemas/OrderCreated',
                        'order.cancelled' => '#/components/schemas/OrderCancelled',
                    ]],
                ]]],
            ])], 'get')],
        ));

        $list = Calls::fromArray($namespace . '\\Model\\EventsListResponse', ['events' => [
            ['type' => 'order.cancelled', 'id' => 'e_1'],
            ['type' => 'order.created', 'id' => 'e_2'],
        ]]);
        $events = Calls::get($list, 'events');
        self::assertIsArray($events);
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\OrderCancelled'), $events[0]);
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\OrderCreated'), $events[1]);

        $this->expectExceptionObject(new UnexpectedResponse('$.events[0].type', 'unknown type "order.lost"'));
        Calls::fromArray($namespace . '\\Model\\EventsListResponse', ['events' => [['type' => 'order.lost', 'id' => 'e_3']]]);
    }

    public function testAnOpenUnionDecodesAVariantAddedLaterAsUnknown(): void
    {
        $namespace = $this->load(self::productSpec(), self::openProducts());
        $decoder = $namespace . '\\Model\\ProductsGetResponseProductsItemModel';

        self::assertInstanceOf(Calls::class($decoder . 'Mug'), Calls::decode($decoder, ['kind' => 'mug', 'heightMm' => 95]));

        $later = ['kind' => 'tote', 'handleMm' => 600];
        $unknown = Calls::decode($decoder, $later);
        self::assertInstanceOf(UnknownVariant::class, $unknown);
        self::assertSame('kind', $unknown->field);
        self::assertSame('tote', $unknown->value);
        self::assertSame($later, $unknown->raw());
        self::assertSame($later, $unknown->toArray());

        $products = Calls::fromArray($namespace . '\\Model\\ProductsGetResponse', ['products' => [
            ['model' => $later],
            ['model' => ['kind' => 'poster', 'widthMm' => 210]],
        ]]);
        $read = Calls::get($products, 'products');
        self::assertIsArray($read);
        self::assertCount(2, $read);
    }

    public function testAnOpenUnionStillRefusesAKnownVariantItCannotRead(): void
    {
        $decoder = $this->load(self::productSpec(), self::openProducts()) . '\\Model\\ProductsGetResponseProductsItemModel';

        try {
            Calls::decode($decoder, ['kind' => 'mug', 'heightMm' => 'tall']);
            self::fail('A mug with a wrong height decoded.');
        } catch (UnexpectedResponse $e) {
            self::assertSame('$.heightMm', $e->jsonPath);
        }

        try {
            Calls::decode($decoder, ['heightMm' => 95]);
            self::fail('A product model without a kind decoded.');
        } catch (UnexpectedResponse $e) {
            self::assertSame('$', $e->jsonPath);
        }

        $this->expectException(UnexpectedResponse::class);
        Calls::decode($decoder, ['kind' => 7]);
    }

    public function testAUnionNotListedAsOpenRefusesAVariantAddedLater(): void
    {
        $decoder = $this->load(self::productSpec()) . '\\Model\\ProductsGetResponseProductsItemModel';

        $this->expectException(UnexpectedResponse::class);
        Calls::decode($decoder, ['kind' => 'tote', 'handleMm' => 600]);
    }

    public function testAnOpenUnionNeedsEveryVariantTaggedByTheSameField(): void
    {
        $spec = self::productSpec([
            ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['mug']]]],
            ['type' => 'object', 'required' => ['widthMm'], 'properties' => ['widthMm' => ['type' => 'number']]],
        ]);

        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('$.products[].model: generator/config.php lists it as an open union');
        $this->load($spec, self::openProducts());
    }

    public function testAnOpenUnionNoAnswerHasIsRefused(): void
    {
        $this->expectExceptionObject(new GeneratorException('generator/config.php lists .parts[].model as an open union, which no answer of the spec has.'));
        $this->load(self::productSpec(), new Config(resources: [self::fixtureTag() => null], openUnions: ['.parts[].model' => 'Fixture.']));
    }

    private static function openProducts(): Config
    {
        return new Config(resources: [self::fixtureTag() => null], openUnions: ['.products[].model' => 'Fixture.']);
    }

    /**
     * An answer listing products, each with a model of one of `$variants`.
     *
     * @param list<array<string, mixed>>|null $variants A mug and a poster, tagged by `kind`, when left out.
     *
     * @return array<string, mixed>
     */
    private static function productSpec(?array $variants = null): array
    {
        $variants ??= [
            ['type' => 'object', 'required' => ['kind', 'heightMm'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['mug']], 'heightMm' => ['type' => 'number']]],
            ['type' => 'object', 'required' => ['kind', 'widthMm'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['poster']], 'widthMm' => ['type' => 'number']]],
        ];

        return self::spec([], ['/v1/products' => self::operation('products.get', ['responses' => self::json200([
            'type' => 'object',
            'required' => ['products'],
            'properties' => ['products' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['model'],
                'properties' => ['model' => ['oneOf' => $variants]],
            ]]],
        ])], 'get')]);
    }

    public function testANullVariantMakesTheOtherNullable(): void
    {
        $namespace = $this->load(self::spec(['Box' => [
            'type' => 'object',
            'required' => ['inner'],
            'properties' => ['inner' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
                ['type' => 'null'],
            ]]],
        ]]));

        self::assertNull(Calls::get(Calls::fromArray($namespace . '\\Model\\Box', ['inner' => null]), 'inner'));
        self::assertInstanceOf(
            Calls::class($namespace . '\\Model\\BoxInner'),
            Calls::get(Calls::fromArray($namespace . '\\Model\\Box', ['inner' => ['x' => 1]]), 'inner'),
        );
    }
}
