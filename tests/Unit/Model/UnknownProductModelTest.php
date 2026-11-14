<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Model\BlanksGetResponseProductModelsItemModel;
use Pixonaut\Sdk\Generated\Model\BlanksListResponseBlanksItemProductModelsItemModel;
use Pixonaut\Sdk\Generated\Model\PrintshopCatalogueProductsImportBlankResponse200BlankProductModelsItemModel;
use Pixonaut\Sdk\Generated\Model\PrintshopCatalogueProductsImportBlankResponse202BlankProductModelsItemModel;
use Pixonaut\Sdk\Generated\Model\ScenesListResponseStockItemsItemModel;
use Pixonaut\Sdk\Model\Model;
use Pixonaut\Sdk\Model\UnknownVariant;

/**
 * A blank's or a stock item's product model of a kind added to the API after
 * this release.
 */
final class UnknownProductModelTest extends TestCase
{
    private const LATER_MODEL = ['kind' => 'folding-box', 'widthMm' => 120, 'flaps' => ['top', 'bottom']];

    /**
     * @return array<string, array{callable(mixed): Model}>
     */
    public static function decoders(): array
    {
        return [
            'one blank' => [BlanksGetResponseProductModelsItemModel::decode(...)],
            'a list of blanks' => [BlanksListResponseBlanksItemProductModelsItemModel::decode(...)],
            'an imported blank' => [PrintshopCatalogueProductsImportBlankResponse200BlankProductModelsItemModel::decode(...)],
            'a blank being imported' => [PrintshopCatalogueProductsImportBlankResponse202BlankProductModelsItemModel::decode(...)],
            'a stock item' => [ScenesListResponseStockItemsItemModel::decode(...)],
        ];
    }

    /**
     * @param callable(mixed): Model $decode
     */
    #[DataProvider('decoders')]
    public function testAKindThisReleaseDoesNotKnowIsReadAsUnknown(callable $decode): void
    {
        $model = $decode(self::LATER_MODEL);

        self::assertInstanceOf(UnknownVariant::class, $model);
        self::assertSame('kind', $model->field);
        self::assertSame('folding-box', $model->value);
        self::assertSame(self::LATER_MODEL, $model->raw());
    }
}
