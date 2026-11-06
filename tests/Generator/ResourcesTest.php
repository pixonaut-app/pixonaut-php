<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use Pixonaut\Sdk\Generator\Codegen\GeneratorException;
use Pixonaut\Sdk\Http\Binary;
use Pixonaut\Sdk\Http\Catalogue;
use Pixonaut\Sdk\Http\EventStream;
use Pixonaut\Sdk\Http\Operation;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;
use ReflectionMethod;
use ReflectionParameter;

final class ResourcesTest extends TestCase
{
    use GeneratesCode;

    public function testNamesEachMethodAfterItsOperation(): void
    {
        $namespace = $this->load($this->fixture(), $this->config());

        self::assertSame([
            'list' => 'designs.list',
            'commit' => 'designs.commit',
            'listRevisions' => 'designs.revisions.list',
            'getRevision' => 'designs.revisions.get',
            'getProof' => 'designs.proof.get',
            'erase' => 'designs.erasePersonalData',
            'delete' => 'designs.delete',
        ], Calls::constant($namespace . '\\Resource\\Designs', 'OPERATIONS'));
        self::assertSame(['exchangeCode' => 'connectedStore.exchangeCode'], Calls::constant($namespace . '\\Resource\\Connect', 'OPERATIONS'));
        self::assertTrue(method_exists($namespace . '\\Resources', 'designs'));
        self::assertTrue(method_exists($namespace . '\\Resources', 'connect'));
        self::assertFalse(class_exists($namespace . '\\Resource\\ApiKeys'), 'An operation mapped to no resource gets no method.');
    }

    public function testTakesPathValuesFirstThenTheBodyQueryHeadersAndKey(): void
    {
        $namespace = $this->load($this->fixture(), $this->config());
        $designs = $namespace . '\\Resource\\Designs';

        self::assertSame(
            ['string $id', 'string $revision', 'string $view'],
            self::signature($designs, 'getRevision'),
        );
        self::assertSame(
            [$namespace . '\\Model\\DesignsCommitRequest $body', '?string $lang = null', '?string $session = null', '?string $idempotencyKey = null'],
            self::signature($designs, 'commit'),
        );
        self::assertSame(
            ['?int $page = null', 'array|string|null $ids = null'],
            self::signature($designs, 'list'),
        );
        self::assertSame(PreparedRequest::class, (string) (new ReflectionMethod($designs, 'prepareCommit'))->getReturnType());
        self::assertSame($namespace . '\\Model\\DesignsCommitResponse', (string) (new ReflectionMethod($designs, 'sendCommit'))->getReturnType());
        self::assertFalse(method_exists($designs, 'prepareList'), 'Only an idempotent operation has a prepare variant.');
    }

    public function testReturnsAModelAFileNothingOrOneOfSeveralAnswers(): void
    {
        $namespace = $this->load($this->fixture(), $this->config());
        $designs = $namespace . '\\Resource\\Designs';

        self::assertSame(Binary::class, (string) (new ReflectionMethod($designs, 'getProof'))->getReturnType());
        self::assertSame('void', (string) (new ReflectionMethod($designs, 'delete'))->getReturnType());
        self::assertSame('?' . $namespace . '\\Model\\DesignsErasePersonalDataResponse', (string) (new ReflectionMethod($designs, 'erase'))->getReturnType());
    }

    public function testReturnsAnEventStreamForServerSentEvents(): void
    {
        $namespace = $this->load($this->streaming(['text/event-stream' => ['schema' => ['type' => 'string']]]), $this->config());
        $designs = $namespace . '\\Resource\\Designs';

        self::assertSame(EventStream::class, (string) (new ReflectionMethod($designs, 'watch'))->getReturnType());
        $catalogue = Calls::call($namespace . '\\Operations', 'catalogue');
        self::assertInstanceOf(Catalogue::class, $catalogue);
        self::assertSame(Operation::EVENT_STREAM, $catalogue->operation('designs.watch')->accept);
        self::assertStringContainsString('depends on the PSR-18 client', (string) (new ReflectionMethod($designs, 'watch'))->getDocComment());
    }

    public function testRefusesServerSentEventsBesideAnotherAnswer(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.watch answers both server-sent events and something else, which is not supported.');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\MixedStream', $this->config()))->files(self::json($this->streaming(
            ['text/event-stream' => ['schema' => ['type' => 'string']]],
            ['201' => ['description' => 'Nothing yet']],
        )));
    }

    public function testDocumentsTheSummaryAndTheErrorCodes(): void
    {
        $files = (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Docs', $this->config()))->files(self::json($this->fixture()));

        self::assertStringContainsString('Commit a design.', $files['Resource/Designs.php']);
        self::assertStringContainsString('(documented: `proof_expired`, `unauthorized`)', $files['Resource/Designs.php']);
    }

    public function testRefusesAnOperationInATagTheConfigDoesNotMap(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.list is tagged "Designs", which generator/config.php does not map to a resource.');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Unmapped', new Config(resources: ['API keys' => 'connect'])))
            ->files(self::json($this->fixture()));
    }

    public function testRefusesTwoOperationsWithOneMethodName(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.list and designs.commit would both be designs()->list()');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Clash', $this->config(['designs.commit' => 'list'])))
            ->files(self::json($this->fixture()));
    }

    public function testRefusesANameAnIdempotentOperationsPrepareMethodTakes(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.commit and designs.revisions.list would both be designs()->prepareCommit()');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Helper', $this->config(['designs.revisions.list' => 'prepareCommit'])))
            ->files(self::json($this->fixture()));
    }

    public function testRefusesNamesThatDifferOnlyInCase(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.list and designs.revisions.list would both be designs()->List()');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Case', $this->config(['designs.revisions.list' => 'List'])))
            ->files(self::json($this->fixture()));
    }

    public function testRefusesTheConstructorAsAMethodName(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('the constructor and designs.list would both be designs()->__construct()');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Constructor', $this->config(['designs.list' => '__construct'])))
            ->files(self::json($this->fixture()));
    }

    public function testRefusesAMethodNameForAnOperationTheSpecDoesNotHave(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('generator/config.php names designs.gone, which the spec does not have.');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Gone', $this->config(['designs.gone' => 'gone'])))
            ->files(self::json($this->fixture()));
    }

    /**
     * @return list<string>
     */
    private static function signature(string $class, string $method): array
    {
        return array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getType() . ' $' . $parameter->getName()
                . ($parameter->isDefaultValueAvailable() ? ' = ' . json_encode($parameter->getDefaultValue()) : ''),
            (new ReflectionMethod($class, $method))->getParameters(),
        );
    }

    /**
     * @param array<string, string> $methodNames
     */
    private function config(array $methodNames = ['designs.erasePersonalData' => 'erase']): Config
    {
        return new Config(
            resources: ['API keys' => 'connect', 'Designs' => 'designs'],
            operationResources: ['apiKeys.list' => null],
            resourcePrefixes: ['connect' => 'connectedStore'],
            methodNames: $methodNames,
        );
    }

    public function testRefusesServerSentEventsBesideAnotherMediaType(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('designs.watch answers both server-sent events and something else, which is not supported.');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\MixedType', $this->config()))->files(self::json($this->streaming(
            ['text/event-stream' => ['schema' => ['type' => 'string']], 'image/png' => []],
        )));
    }

    /**
     * The fixture with `designs.watch` answering 200 with the content given.
     *
     * @param array<string, mixed>     $content
     * @param array<int|string, mixed> $more    Further successful answers, by status.
     *
     * @return array<string, mixed>
     */
    private function streaming(array $content, array $more = []): array
    {
        $spec = $this->fixture();
        $paths = $spec['paths'];
        self::assertIsArray($paths);
        $paths['/v1/designs/watch'] = ['post' => [
            'tags' => ['Designs'],
            'operationId' => 'designs.watch',
            'responses' => ['200' => ['description' => 'Events', 'content' => $content]] + $more,
        ]];

        return ['paths' => $paths] + $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $designs = ['tags' => ['Designs']];
        $object = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];
        $id = static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];

        return self::spec([], [
            '/v1/designs' => [
                'get' => $designs + [
                    'operationId' => 'designs.list',
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'ids', 'in' => 'query', 'schema' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]]],
                    ],
                    'responses' => self::json200($object),
                ],
                'post' => $designs + [
                    'operationId' => 'designs.commit',
                    'summary' => 'Commit a design',
                    'parameters' => [
                        ['name' => 'lang', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'x-pixonaut-session', 'in' => 'header', 'schema' => ['type' => 'string']],
                        ['name' => 'idempotency-key', 'in' => 'header', 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $object]]],
                    'responses' => ['201' => ['description' => 'Made', 'content' => ['application/json' => ['schema' => $object]]]]
                        + ['401' => ['description' => 'No', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string', 'enum' => ['unauthorized']]]]]]]]
                        + ['410' => ['description' => 'Gone', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string', 'enum' => ['proof_expired']]]]]]]],
                ],
            ],
            '/v1/designs/{id}/revisions' => ['get' => $designs + ['operationId' => 'designs.revisions.list', 'parameters' => [$id('id')], 'responses' => self::json200($object)]],
            '/v1/designs/{id}/revisions/{revision}/{view}' => ['get' => $designs + [
                'operationId' => 'designs.revisions.get',
                'parameters' => [$id('id'), $id('revision'), $id('view')],
                'responses' => self::json200($object),
            ]],
            '/v1/designs/{id}/proof' => ['get' => $designs + [
                'operationId' => 'designs.proof.get',
                'parameters' => [$id('id')],
                'responses' => ['200' => ['description' => 'The proof', 'content' => ['image/jpeg' => []]]],
            ]],
            '/v1/designs/{id}/erase' => ['post' => $designs + [
                'operationId' => 'designs.erasePersonalData',
                'parameters' => [$id('id')],
                'responses' => self::json200($object) + ['204' => ['description' => 'Already gone']],
            ]],
            '/v1/designs/{id}' => ['delete' => $designs + ['operationId' => 'designs.delete', 'parameters' => [$id('id')], 'responses' => ['204' => ['description' => 'Gone']]]],
            '/v1/connect/token' => ['post' => ['tags' => ['API keys'], 'operationId' => 'connectedStore.exchangeCode', 'responses' => self::json200($object)]],
            '/v1/api-keys' => ['get' => ['tags' => ['API keys'], 'operationId' => 'apiKeys.list', 'responses' => self::json200($object)]],
        ]);
    }
}
