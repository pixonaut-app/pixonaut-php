<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use Pixonaut\Sdk\Generator\Codegen\GeneratorException;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;

final class TreeTest extends TestCase
{
    use GeneratesCode;

    private const NAMESPACE = 'Pixonaut\\Sdk\\Tests\\Generator\\Tree';

    private const THING = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];

    public function testNamesInlineSchemasAfterTheOperationAndTheirPlace(): void
    {
        $files = $this->generator()->files(self::json(self::spec([], [
            '/v1/orders' => self::operation('orders.create', [
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['print' => self::THING, 'items' => ['type' => 'array', 'items' => self::THING]],
                ]]]],
                'responses' => self::json200(self::THING) + ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => self::THING]]]],
            ]),
        ])));

        self::assertSame([
            'ApiVersion.php',
            'ErrorCode.php',
            'Model/OrdersCreateRequest.php',
            'Model/OrdersCreateRequestItemsItem.php',
            'Model/OrdersCreateRequestPrint.php',
            'Model/OrdersCreateResponse200.php',
            'Model/OrdersCreateResponse201.php',
            'Operations.php',
        ], array_keys($files));
    }

    public function testRefusesTwoPlacesThatWouldShareAName(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessageMatches('/would both be class OrderPrintFile/');

        $this->generator()->files(self::json(self::spec([
            'Order' => ['type' => 'object', 'properties' => [
                'printFile' => self::THING,
                'print_file' => self::THING,
            ]],
        ])));
    }

    public function testOutputIsTheSameBytesEveryRun(): void
    {
        $spec = self::json(self::spec(['Thing' => self::THING, 'Other' => self::THING]));

        self::assertSame($this->generator()->files($spec), $this->generator()->files($spec));
    }

    public function testWritingRemovesTheFileOfASchemaThatWasRemoved(): void
    {
        $directory = $this->directory();
        $this->generator()->write(self::json(self::spec(['Thing' => self::THING, 'Other' => self::THING])), $directory);
        self::assertFileExists($directory . '/Model/Other.php');

        [, $removed] = $this->generator()->write(self::json(self::spec(['Thing' => self::THING])), $directory);

        self::assertSame(1, $removed);
        self::assertFileDoesNotExist($directory . '/Model/Other.php');
        self::assertFileExists($directory . '/Model/Thing.php');
    }

    public function testCheckPassesOnAFreshTreeAndListsEachDifferingFile(): void
    {
        $spec = self::json(self::spec(['Thing' => self::THING, 'Other' => self::THING]));
        $directory = $this->directory();
        $this->generator()->write($spec, $directory);
        self::assertSame([], $this->generator()->check($spec, $directory));

        file_put_contents($directory . '/Model/Thing.php', "\n// a hand edit\n", FILE_APPEND);
        file_put_contents($directory . '/Model/Stray.php', "<?php\n");
        unlink($directory . '/ErrorCode.php');

        self::assertSame(
            ['ErrorCode.php: missing', 'Model/Stray.php: not generated', 'Model/Thing.php: differs'],
            $this->generator()->check($spec, $directory),
        );
    }

    public function testCheckFailsWhenTheSpecChanges(): void
    {
        $directory = $this->directory();
        $this->generator()->write(self::json(self::spec(['Thing' => self::THING])), $directory);

        $changed = self::spec(['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]);

        self::assertSame(
            ['ApiVersion.php: differs', 'Model/Thing.php: differs'],
            $this->generator()->check(self::json($changed), $directory),
        );
    }

    private function generator(): Generator
    {
        return new Generator(self::NAMESPACE, new Config());
    }
}
