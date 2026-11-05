<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class PrepareTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testEncodesPathValues(): void
    {
        $request = (new Harness())->pixonaut->prepare('designs.get', ['id' => 'a b/c']);

        self::assertSame('/v1/designs/a%20b%2Fc', $request->target);
    }

    /**
     * @return iterable<string, array{array<string, string|int>}>
     */
    public static function badPathValues(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['id' => '']];
        yield 'dot segment' => [['id' => '..']];
        yield 'not in the template' => [['id' => 'd_1', 'other' => 'x']];
    }

    /**
     * @param array<string, string|int> $path
     */
    #[DataProvider('badPathValues')]
    public function testRefusesABadPathValue(array $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Harness())->pixonaut->prepare('designs.get', $path);
    }

    public function testEncodesTheQueryRfc3986AndDropsNulls(): void
    {
        $request = (new Harness())->pixonaut->prepare('templates.list', [], [
            'q' => 'a b+c',
            'after' => null,
            'limit' => 10,
            'archived' => false,
        ]);

        self::assertSame('/v1/templates?q=a%20b%2Bc&limit=10&archived=false', $request->target);
    }

    public function testEncodesAnEmptyBodyAsAnObject(): void
    {
        $harness = new Harness(listener: new \Pixonaut\Sdk\Http\NullListener());

        $request = $harness->pixonaut->prepare('designs.update', ['id' => 'd_1'], [], []);

        self::assertSame('{}', $request->body);
    }

    public function testEncodesTheBodyWithUnescapedSlashesAndUnicode(): void
    {
        $harness = new Harness(listener: new \Pixonaut\Sdk\Http\NullListener());

        $request = $harness->pixonaut->prepare('designs.update', ['id' => 'd_1'], [], ['url' => 'https://x/y', 'name' => 'Åsa']);

        self::assertSame('{"url":"https://x/y","name":"Åsa"}', $request->body);
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Harness())->pixonaut->prepare('designs.update', ['id' => 'd_1'], [], ['bad' => "\xB1"]);
    }

    public function testChoosesAUuidKeyForAnIdempotentOperation(): void
    {
        $pixonaut = (new Harness())->pixonaut;

        $first = $pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], []);
        $second = $pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], []);

        self::assertMatchesRegularExpression(self::UUID_V4, (string) $first->idempotencyKey);
        self::assertNotSame($first->idempotencyKey, $second->idempotencyKey);
    }

    public function testKeepsTheCallersKey(): void
    {
        $request = (new Harness())->pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], [], 'order-42');

        self::assertSame('order-42', $request->idempotencyKey);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'non-ASCII' => ['å'];
        yield 'too long' => [str_repeat('k', 256)];
    }

    #[DataProvider('badKeys')]
    public function testRefusesAKeyPixonautDoesNotAccept(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Harness())->pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], [], $key);
    }

    public function testRefusesAKeyForAnOperationThatTakesNone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Harness())->pixonaut->prepare('designs.get', ['id' => 'd_1'], [], null, 'key-1');
    }

    public function testRefusesAnUnknownOperation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Harness())->pixonaut->prepare('nope.get');
    }

    public function testSnapshotRoundTripsAndResendsTheSameBytesAndKey(): void
    {
        $harness = new Harness();
        $harness->answer(201);
        $harness->answer(201, '{}', ['Idempotent-Replayed' => 'true']);
        $request = $harness->pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], ['proof' => 'p_1']);

        $first = $harness->pixonaut->send($request);
        $stored = json_decode((string) json_encode($request->toArray()), true);
        self::assertIsArray($stored);
        $restored = PreparedRequest::fromArray($stored);
        $second = $harness->pixonaut->send($restored);

        self::assertEquals($request, $restored);
        [$a, $b] = $harness->sent();
        self::assertSame((string) $a->getBody(), (string) $b->getBody());
        self::assertSame($a->getHeaderLine('Idempotency-Key'), $b->getHeaderLine('Idempotency-Key'));
        self::assertFalse($first->replayed());
        self::assertTrue($second->replayed());
        self::assertSame(['body designs.commit', 'before designs.commit', 'after designs.commit', 'before designs.commit', 'after designs.commit'], $harness->listener->calls);
    }

    public function testSnapshotHoldsNoCredential(): void
    {
        $request = (new Harness())->pixonaut->prepare('designs.commit', ['id' => 'd_1'], [], []);

        self::assertStringNotContainsString(Harness::INTEGRATION_KEY, (string) json_encode($request->toArray()));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedSnapshots(): iterable
    {
        $good = ['operation' => 'designs.commit', 'target' => '/v1/designs/d_1/commit', 'body' => '{}', 'idempotencyKey' => 'k'];
        foreach (array_keys($good) as $field) {
            $missing = $good;
            unset($missing[$field]);
            yield 'no ' . $field => [$missing];
        }
        yield 'implied null key' => [['operation' => 'designs.get', 'target' => '/v1/designs/d_1', 'body' => null]];
        yield 'operation not a string' => [['operation' => 1] + $good];
        yield 'body not a string' => [['body' => []] + $good];
        yield 'key not a string' => [['idempotencyKey' => 5] + $good];
    }

    /**
     * @param array<mixed> $snapshot
     */
    #[DataProvider('malformedSnapshots')]
    public function testRefusesAMalformedSnapshot(array $snapshot): void
    {
        $this->expectException(InvalidArgumentException::class);

        PreparedRequest::fromArray($snapshot);
    }

    /**
     * @return iterable<string, array{PreparedRequest}>
     */
    public static function mismatchedSnapshots(): iterable
    {
        yield 'target of another operation' => [new PreparedRequest('designs.get', '/v1/templates')];
        yield 'idempotent without a key' => [new PreparedRequest('designs.commit', '/v1/designs/d_1/commit', '{}')];
        yield 'key on a non-idempotent operation' => [new PreparedRequest('designs.get', '/v1/designs/d_1', null, 'k')];
        yield 'bad key' => [new PreparedRequest('designs.commit', '/v1/designs/d_1/commit', '{}', "k\n")];
        yield 'unknown operation' => [new PreparedRequest('nope', '/v1/nope')];
        yield 'extra segment' => [new PreparedRequest('designs.get', '/v1/designs/d_1/x')];
    }

    #[DataProvider('mismatchedSnapshots')]
    public function testNeverSendsAMismatchedSnapshot(PreparedRequest $request): void
    {
        $harness = new Harness();

        try {
            $harness->pixonaut->send($request);
            self::fail('Expected the request to be refused.');
        } catch (InvalidArgumentException) {
        }
        try {
            $harness->pixonaut->toPsrRequest($request);
            self::fail('Expected the request to be refused.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $harness->sent());
        self::assertSame([], $harness->listener->calls);
    }
}
