<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Http\EventStream;
use Pixonaut\Sdk\Http\StreamEvent;
use Pixonaut\Sdk\Tests\Contract\Support\Arguments;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Spec;
use Pixonaut\Sdk\Tests\Fake\SpecOperation;
use ReflectionMethod;
use Throwable;

/**
 * Every operation the SDK sends, through its resource method, against the
 * fake: the fake accepts the request
 * the SDK makes from the spec's examples, and the SDK reads each success
 * answer the spec documents.
 */
final class OperationsTest extends TestCase
{
    /**
     * Operations the spec gives no request or success example for, so the
     * table cannot build a call or an answer for them, with the reason.
     *
     * @var array<string, string>
     */
    private const NO_EXAMPLE = [];

    /**
     * @return array<string, string>
     */
    private static function noExample(): array
    {
        return self::NO_EXAMPLE;
    }

    public function testEveryOperationWithoutAnExampleIsListed(): void
    {
        $missing = [];
        foreach (Operations::all() as $operation) {
            $spec = Spec::pinned()->operation($operation->id);
            $lacks = $spec->hasBody() && $spec->requestExample() === null;
            foreach ($spec->successStatuses() as $status) {
                $lacks = $lacks || (in_array(SpecOperation::JSON, $spec->mediaTypes($status), true) && $spec->answerExample($status) === null);
            }
            if ($lacks) {
                $missing[] = $operation->id;
            }
        }

        self::assertSame(array_keys(self::noExample()), $missing);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function operations(): iterable
    {
        foreach (Operations::all() as $operation) {
            if (isset(self::noExample()[$operation->id])) {
                continue;
            }
            foreach (Spec::pinned()->operation($operation->id)->successStatuses() as $status) {
                yield $operation->id . ' ' . $status => [$operation->id, $status];
            }
        }
    }

    #[DataProvider('operations')]
    public function testTheFakeAcceptsTheRequestAndTheSdkReadsTheAnswer(string $id, int $status): void
    {
        $client = new ContractClient();
        $fake = $client->fake();
        $spec = Spec::pinned()->operation($id);
        $fake->answerExample($id, $status);

        $sentBy = Arguments::resourceMethods()[$id] ?? self::fail($id . ' has no resource method.');
        try {
            [$accessor, $method] = $sentBy;
            $resource = $client->pixonaut->{$accessor}();
            self::assertIsObject($resource);
            $arguments = Arguments::forMethod($spec, new ReflectionMethod($resource, $method));
            $returned = $resource->{$method}(...$arguments);
        } catch (Throwable $e) {
            self::fail(sprintf("%s was refused:\n%s\n%s: %s", $id, implode("\n", $fake->violations()), $e::class, $e->getMessage()));
        }

        self::assertSame([], $fake->violations(), $id . ' broke the spec.');
        self::assertSame([$id, $status], [$fake->log()[0]['operation'] ?? null, $fake->log()[0]['status'] ?? null]);
        self::assertCount(1, $fake->log());
        if ($spec->mediaTypes($status) !== []) {
            self::assertNotNull($returned, $id . ' read nothing from its answer.');
        }
        if ($returned instanceof EventStream) {
            self::assertSame(
                [['delta', '{"text":"Hel"}', '1'], ['done', "{\"text\":\"lo\"}\n{}", '2']],
                array_map(static fn(StreamEvent $event): array => [$event->event, $event->data, $event->id], iterator_to_array($returned, false)),
            );
        }
    }
}
