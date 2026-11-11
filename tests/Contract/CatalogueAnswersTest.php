<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Model\PrintshopCatalogueProductsImportBlankRequest;
use Pixonaut\Sdk\Generated\Model\PrintshopCatalogueProductsImportBlankResponse200;
use Pixonaut\Sdk\Generated\Model\PrintshopCatalogueProductsImportBlankResponse202;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;

/**
 * A printshop's catalogue as the API answers it: its products, and a product
 * imported as a blank, either still being filled in by a job or imported
 * before. Every answer is the spec's own example (the fake checks it), and the
 * SDK reads it.
 */
final class CatalogueAnswersTest extends TestCase
{
    private const HTTP_OK = 200;

    private const HTTP_ACCEPTED = 202;

    private const CONNECTION_ID = 'psc_printful';

    /** The product the spec's examples import. */
    private const PRODUCT_ID = '71';

    public function testTheCataloguesProductsAreRead(): void
    {
        $client = new ContractClient();
        $client->fake()->answerExample('printshopCatalogueProducts.list', self::HTTP_OK);

        $page = $client->pixonaut->printshops()->listCatalogueProducts(self::CONNECTION_ID, search: 'bella');

        self::assertSame([], $client->fake()->violations());
        self::assertNotSame([], $page->products);
        $product = $page->products[0];
        self::assertSame(self::PRODUCT_ID, $product->productId);
        self::assertTrue($product->hasImportedBlank);
        self::assertNotNull($product->importedBlankId);
    }

    public function testAProductBeingImportedIsReadWithItsJob(): void
    {
        $client = new ContractClient();
        $client->fake()->answerExample('printshopCatalogueProducts.importBlank', self::HTTP_ACCEPTED);

        $import = $client->pixonaut->printshops()->importCatalogueProduct(
            self::PRODUCT_ID,
            self::CONNECTION_ID,
            new PrintshopCatalogueProductsImportBlankRequest(name: 'Bella 3001'),
        );

        self::assertSame([], $client->fake()->violations());
        self::assertInstanceOf(PrintshopCatalogueProductsImportBlankResponse202::class, $import);
        self::assertSame('blank_bella_3001', $import->blank->id);
        self::assertNotSame('', $import->job->id);
    }

    public function testAProductImportedBeforeAnswersItsBlank(): void
    {
        $client = new ContractClient();
        $client->fake()->answerExample('printshopCatalogueProducts.importBlank', self::HTTP_OK);

        $import = $client->pixonaut->printshops()->importCatalogueProduct(self::PRODUCT_ID, self::CONNECTION_ID);

        self::assertSame([], $client->fake()->violations());
        self::assertInstanceOf(PrintshopCatalogueProductsImportBlankResponse200::class, $import);
        self::assertSame('blank_bella_3001', $import->blank->id);
    }
}
