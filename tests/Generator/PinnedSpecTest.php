<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\ApiVersion;
use Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Generator\Codegen\Api;
use Pixonaut\Sdk\Generator\Codegen\Compiler;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\SecretScan;
use Pixonaut\Sdk\Generator\Codegen\Type;
use Pixonaut\Sdk\Model\Encode;
use Pixonaut\Sdk\Model\Model;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\JsonSchema;

/**
 * The generated tree against the pinned spec itself.
 */
final class PinnedSpecTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../spec/openapi.json';

    private const LOCK = __DIR__ . '/../../spec/spec.lock.json';

    private const CONFIG = __DIR__ . '/../../generator/config.php';

    private const MODELS = 'Pixonaut\\Sdk\\Generated\\Model\\';

    /** How a data set names a body that is a list of models. */
    private const LIST_OF = 'list:';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private static ?Api $api = null;

    public function testTargetsThePinnedRelease(): void
    {
        $lock = json_decode((string) file_get_contents(self::LOCK), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        self::assertSame($lock['version'], ApiVersion::TARGET);
        self::assertSame($lock['sha256'], ApiVersion::SPEC_SHA256);
    }

    public function testTheCatalogueHoldsEveryOperationTheSdkCalls(): void
    {
        $catalogue = Operations::catalogue();
        foreach (self::api()->operations as $operation) {
            self::assertSame($operation->path, $catalogue->operation($operation->id)->path);
        }
        self::assertTrue($catalogue->isKnownErrorCode(ErrorCode::UNAUTHORIZED));
        self::assertSame([401], ErrorCode::statuses(ErrorCode::UNAUTHORIZED));
        self::assertTrue($catalogue->isKnownStatus(429));
    }

    public function testEveryAnswerFieldNamedLikeASecretIsOnASensitiveOperationOrExplained(): void
    {
        $config = Config::load(self::CONFIG);
        $found = SecretScan::find(self::api());

        $unexplained = [];
        foreach ($found as $operation => $paths) {
            if (isset($config->sensitive[$operation])) {
                continue;
            }
            foreach ($paths as $path) {
                if (!isset($config->notSecret[$operation][$path])) {
                    $unexplained[] = $operation . ' ' . $path;
                }
            }
        }
        self::assertSame([], $unexplained, 'Mark the operation sensitive in generator/config.php, or say why the field is not a secret.');

        $stale = [];
        foreach ($config->notSecret as $operation => $paths) {
            foreach (array_keys($paths) as $path) {
                if (!in_array($path, $found[$operation] ?? [], true)) {
                    $stale[] = $operation . ' ' . $path;
                }
            }
        }
        self::assertSame([], $stale, 'generator/config.php explains fields the spec no longer has.');
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function responseExamples(): iterable
    {
        foreach (self::api()->operations as $operation) {
            foreach ($operation->responses as $status => $type) {
                $content = self::at(self::operationSpec($operation->id), 'responses', (string) $status, 'content', 'application/json');
                foreach (self::examples($content) as $name => $value) {
                    yield sprintf('%s %d %s', $operation->id, $status, $name) => [self::className($type), $value];
                }
            }
        }
    }

    /**
     * @param string $decoder The class decoding the body, or `list:<class>` for a list of them.
     */
    #[DataProvider('responseExamples')]
    public function testEveryResponseExampleDecodes(string $decoder, mixed $example): void
    {
        if (str_starts_with($decoder, self::LIST_OF)) {
            if (!is_array($example) || !array_is_list($example)) {
                self::fail('The example of a list is not a list.');
            }
            $class = substr($decoder, strlen(self::LIST_OF));
            $decoded = array_map(static fn(mixed $item): mixed => self::decode($class, $item), $example);
            $raw = array_map(static fn(mixed $item): mixed => $item instanceof Model ? $item->raw() : $item, $decoded);
        } else {
            $decoded = self::decode($decoder, $example);
            $raw = $decoded instanceof Model ? $decoded->raw() : $example;
        }

        self::assertSame($example, $raw);
    }

    /**
     * @return iterable<string, array{string, mixed, array<string, mixed>}>
     */
    public static function requestExamples(): iterable
    {
        foreach (self::api()->operations as $operation) {
            if ($operation->request === null) {
                continue;
            }
            $body = self::at(self::operationSpec($operation->id), 'requestBody', 'content', 'application/json');
            foreach (self::examples($body) as $name => $value) {
                yield $operation->id . ' ' . $name => [self::className($operation->request), $value, self::at($body, 'schema')];
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('requestExamples')]
    public function testEveryRequestExampleRoundTripsAndValidates(string $class, mixed $example, array $schema): void
    {
        self::assertIsArray($example);
        /** @var array<string, mixed> $example */
        $model = self::decode($class, $example);
        self::assertInstanceOf(Model::class, $model);
        // As a request sends it: an empty model is still an object.
        $sent = json_decode(json_encode(Encode::value($model), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($example, self::plain($sent));
        $problems = (new JsonSchema(self::spec()))->validate($schema, $sent);
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * A decoded JSON value with objects as arrays, as the spec's examples are.
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }

    private static function decode(string $decoder, mixed $value): mixed
    {
        if (method_exists($decoder, 'fromArray')) {
            self::assertIsArray($value);

            /** @var array<string, mixed> $value */
            return Calls::fromArray($decoder, $value);
        }

        return Calls::decode($decoder, $value);
    }

    private static function className(Type $type): string
    {
        if ($type->kind === Type::LIST && $type->item !== null) {
            return self::LIST_OF . self::className($type->item);
        }
        self::assertContains($type->kind, [Type::MODEL, Type::UNION], 'A body that is not an object has no class.');

        return self::MODELS . $type->class;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function examples(array $content): array
    {
        $examples = [];
        foreach (self::at($content, 'examples') as $name => $example) {
            if (is_array($example) && array_key_exists('value', $example)) {
                $examples[$name] = $example['value'];
            }
        }
        if (array_key_exists('example', $content)) {
            $examples['example'] = $content['example'];
        }

        return $examples;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operationSpec(string $id): array
    {
        foreach (self::at(self::spec(), 'paths') as $item) {
            foreach (self::at($item) as $operation) {
                $operation = self::at($operation);
                if (($operation['operationId'] ?? null) === $id) {
                    return $operation;
                }
            }
        }
        self::fail('No operation ' . $id);
    }

    /**
     * The object at `$keys` inside `$value`; empty when there is none.
     *
     * @return array<string, mixed>
     */
    private static function at(mixed $value, string ...$keys): array
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        if (self::$spec === null) {
            $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($spec);
            /** @var array<string, mixed> $spec */
            self::$spec = $spec;
        }

        return self::$spec;
    }

    private static function api(): Api
    {
        return self::$api ??= (new Compiler(self::spec(), Config::load(self::CONFIG)))->compile();
    }
}
