<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use Pixonaut\Sdk\Generator\Codegen\GeneratorException;
use Pixonaut\Sdk\Http\Catalogue;
use Pixonaut\Sdk\Http\Operation;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;

final class OperationsTest extends TestCase
{
    use GeneratesCode;

    public function testDescribesEachOperationAsTheClientSendsIt(): void
    {
        $operations = $this->operations($this->load($this->fixture(), $this->config()));

        self::assertSame(['orders.create', 'orders.list', 'designs.preview', 'templates.mockup.get'], array_keys($operations));

        $create = $operations['orders.create'];
        self::assertSame('POST', $create->method);
        self::assertSame('/v1/orders', $create->path);
        self::assertSame([Credential::SecretKey, Credential::IntegrationKey], $create->credentials);
        self::assertFalse($create->anonymous);
        self::assertTrue($create->idempotent);
        self::assertSame(Operation::JSON, $create->accept);
        self::assertTrue($create->sensitive);

        $list = $operations['orders.list'];
        self::assertSame([Credential::IntegrationKey], $list->credentials);
        self::assertTrue($list->anonymous);
        self::assertFalse($list->idempotent);
        self::assertFalse($list->sensitive);

        self::assertSame('image/png, image/webp', $operations['designs.preview']->accept);
        self::assertSame([], $operations['designs.preview']->credentials);
    }

    public function testListsTheErrorCodesTheSpecNamesWithTheirStatuses(): void
    {
        $namespace = $this->load($this->fixture(), $this->config());

        self::assertSame(
            ['forbidden' => [403], 'order_closed' => [409, 422], 'unauthorized' => [401]],
            constant($namespace . '\\ErrorCode::STATUSES'),
        );
        self::assertSame('order_closed', constant($namespace . '\\ErrorCode::ORDER_CLOSED'));
        self::assertTrue(Calls::call($namespace . '\\ErrorCode', 'isKnown', 'forbidden'));
        self::assertFalse(Calls::call($namespace . '\\ErrorCode', 'isKnown', 'teapot'));

        $catalogue = Calls::call($namespace . '\\Operations', 'catalogue');
        self::assertInstanceOf(Catalogue::class, $catalogue);
        self::assertTrue($catalogue->isKnownErrorCode('order_closed'));
        self::assertTrue($catalogue->isKnownStatus(409));
        self::assertFalse($catalogue->isKnownStatus(418));
        self::assertSame('POST', $catalogue->operation('orders.create')->method);
    }

    public function testRefusesAConfigNamingAnOperationTheSpecDoesNotHave(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('generator/config.php names orders.delete, which the spec does not have.');

        (new Generator('Pixonaut\\Sdk\\Tests\\Generator\\Config', new Config(sensitive: ['orders.delete' => 'gone'])))
            ->files(self::json($this->fixture()));
    }

    public function testTheVersionIsTheSpecsAndTheHashItsBytes(): void
    {
        $spec = self::json($this->fixture());
        $namespace = 'Pixonaut\\Sdk\\Tests\\Generator\\Version';
        $files = (new Generator($namespace, $this->config()))->files($spec);

        self::assertStringContainsString("TARGET = '9.9.9'", $files['ApiVersion.php']);
        self::assertStringContainsString("SPEC_SHA256 = '" . hash('sha256', $spec) . "'", $files['ApiVersion.php']);
    }

    /**
     * @return array<string, Operation>
     */
    private function operations(string $namespace): array
    {
        $all = Calls::call($namespace . '\\Operations', 'all');
        self::assertIsArray($all);
        $byId = [];
        foreach ($all as $operation) {
            self::assertInstanceOf(Operation::class, $operation);
            $byId[$operation->id] = $operation;
        }

        return $byId;
    }

    private function config(): Config
    {
        return new Config(
            sensitive: ['orders.create' => 'Answers a token.'],
            generateSkipped: ['templates.mockup.get' => 'A server calls it.'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $refused = static fn(int $status, array $codes): array => [(string) $status => [
            'description' => 'Refused',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => ['error' => ['type' => 'string', 'x-extensible-enum' => $codes]],
            ]]],
        ]];

        return self::spec([], [
            '/v1/orders' => [
                'post' => [
                    'operationId' => 'orders.create',
                    'security' => [['secretKey' => []], ['integrationKey' => []]],
                    'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]],
                    'responses' => self::json200(['type' => 'object', 'properties' => ['token' => ['type' => 'string']]])
                        + $refused(409, ['order_closed']) + $refused(422, ['order_closed']),
                ],
                'get' => [
                    'operationId' => 'orders.list',
                    'security' => [['integrationKey' => []], []],
                    'responses' => self::json200(['type' => 'object']) + $refused(403, ['forbidden']),
                ],
            ],
            '/v1/designs/{id}/preview' => self::operation('designs.preview', ['responses' => ['200' => [
                'description' => 'The image',
                'content' => ['image/png' => [], 'image/webp' => []],
            ]]], 'get'),
            '/v1/designs/{id}/print' => self::operation('designs.print.get', ['x-sdk-skip' => true], 'get'),
            '/v1/templates/{id}/mockup' => self::operation('templates.mockup.get', ['x-sdk-skip' => true], 'get'),
        ], ['x-refusals' => [
            'unauthorized' => ['status' => 401, 'description' => 'No credential.'],
            'forbidden' => ['status' => 403, 'description' => 'Not this credential.'],
        ]]);
    }
}
