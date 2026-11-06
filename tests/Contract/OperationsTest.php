<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Http\Operation;
use Pixonaut\Sdk\Tests\Contract\Support\Arguments;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Spec;
use Pixonaut\Sdk\Tests\Fake\SpecOperation;
use ReflectionMethod;
use Throwable;

/**
 * Every operation the SDK sends, through its resource method (or `call()`
 * where it has none yet), against the fake: the fake accepts the request
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

        $sentBy = Arguments::resourceMethods()[$id] ?? null;
        try {
            if ($sentBy !== null) {
                [$accessor, $method] = $sentBy;
                $resource = $client->pixonaut->{$accessor}();
                self::assertIsObject($resource);
                $arguments = Arguments::forMethod($spec, new ReflectionMethod($resource, $method));
                $returned = $resource->{$method}(...$arguments);
            } else {
                $arguments = Arguments::forCall($spec);
                $result = $client->pixonaut->call($id, ...$arguments);
                $returned = match (true) {
                    $result->body === '' => null,
                    self::catalogued($id)->accept === Operation::JSON => $result->json(),
                    default => $result->binary(),
                };
            }
        } catch (Throwable $e) {
            self::fail(sprintf("%s was refused:\n%s\n%s: %s", $id, implode("\n", $fake->violations()), $e::class, $e->getMessage()));
        }

        self::assertSame([], $fake->violations(), $id . ' broke the spec.');
        self::assertSame([$id, $status], [$fake->log()[0]['operation'] ?? null, $fake->log()[0]['status'] ?? null]);
        self::assertCount(1, $fake->log());
        if ($spec->mediaTypes($status) !== []) {
            self::assertNotNull($returned, $id . ' read nothing from its answer.');
        }
    }

    private static function catalogued(string $id): Operation
    {
        foreach (Operations::all() as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }
        self::fail('The SDK does not send ' . $id . '.');
    }
}
