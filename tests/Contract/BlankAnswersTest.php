<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Spec;
use stdClass;

/**
 * A blank as the API answers it: no blank-wide `photoSource`, each view
 * saying its own `source` and `position`, one blank's `order` mixing stored
 * views and scenes, placements and print areas that fold, print areas with a
 * shape. Every answer is one the spec allows (the fake checks it), and the SDK
 * reads it.
 */
final class BlankAnswersTest extends TestCase
{
    private const HTTP_OK = 200;

    /** A print method no release names yet: the field stays a string. */
    private const UNKNOWN_PRINT_METHOD = 'foil-transfer';

    /** The highest pattern piece the spec lets a UV pass mark. */
    private const LAST_PIECE = 253;

    /** The hash a folding box's flat layout is known by. */
    private const FLAT_LAYOUT_KEY = '9f2c4e1a7b3d5f60';

    /** An outline in fractions of the print area, with one cut-out. */
    private const ROUNDED_SHAPE = 'M0.1 0C0 0 0 0.1 0 0.1V0.9C0 1 0.1 1 0.1 1H0.9C1 1 1 0.9 1 0.9V0.1C1 0 0.9 0 0.9 0Z M0.4 0.4H0.6V0.6H0.4Z';

    public function testAListOfBlanksWithoutAPhotoSourceIsRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.list');
        self::withPhotoSources(self::items($answer->blanks)[0]);
        $client->fake()->answer('blanks.list', self::HTTP_OK, $answer);

        $blanks = $client->pixonaut->blanks()->list(hasPlacements: 'true');

        self::assertSame([], $client->fake()->violations());
        $blank = $blanks->blanks[0];
        self::assertArrayNotHasKey('photoSource', $blank->raw());
        self::assertSame(['own', 'stock'], array_map(static fn(object $view): ?string => $view->source, $blank->placements[0]->views));
        self::assertSame([0, 1], array_map(static fn(object $view): ?int => $view->position, $blank->placements[0]->views));
        self::assertSame(self::UNKNOWN_PRINT_METHOD, $blank->placements[0]->printMethod);
        self::assertSame(20.0, $blank->placements[0]->foldMm);
    }

    public function testOneBlankWithoutAPhotoSourceIsReadWithItsOrder(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.get');
        self::withPhotoSources($answer);
        $answer->order = [
            (object) ['source' => 'own', 'viewId' => 'view-front-flat'],
            (object) ['source' => 'stock', 'sceneId' => 'scene_kitchen', 'row' => (object) ['Color' => 'White']],
            (object) ['source' => 'catalogue', 'viewId' => 'view-front-model'],
        ];
        $client->fake()->answer('blanks.get', self::HTTP_OK, $answer);

        $blank = $client->pixonaut->blanks()->get('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        self::assertArrayNotHasKey('photoSource', $blank->raw());
        self::assertSame(
            ['own', 'stock', 'catalogue'],
            array_map(static fn(object $entry): string => $entry->source, $blank->order ?? []),
        );
        self::assertSame(20.0, $blank->placements[0]->foldMm);
        self::assertSame(15.0, $blank->placements[0]->areas[0]->foldMm ?? null);
    }

    public function testAPrintAreaWithAShapeIsRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.get');
        self::withPhotoSources($answer);
        $area = self::items(self::items($answer->placements)[0]->areas)[0];
        $area->shape = (object) ['dFrac' => self::ROUNDED_SHAPE, 'cutByPrintShop' => true];
        $client->fake()->answer('blanks.get', self::HTTP_OK, $answer);

        $blank = $client->pixonaut->blanks()->get('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        $shape = $blank->placements[0]->areas[0]->shape ?? null;
        self::assertSame(self::ROUNDED_SHAPE, $shape?->dFrac);
        self::assertTrue($shape->cutByPrintShop);
    }

    public function testABlankFromAnOlderApiThatStillSendsAPhotoSourceIsRead(): void
    {
        $answer = self::example('blanks.get');
        $answer->photoSource = 'scenes';
        $answer->scenes = [(object) ['id' => 'scene_kitchen', 'colour' => null, 'rowOptions' => []]];
        $client = new ContractClient();
        $client->fake()->answer('blanks.get', self::HTTP_OK, $answer);

        $blank = $client->pixonaut->blanks()->get('blank_tshirt');

        self::assertSame('scenes', $blank->raw()['photoSource']);
    }

    public function testAFoldingPlacementsConstraintsAreRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('templates.constraints.get');
        self::obj($answer->placement)->foldMm = 20;
        $client->fake()->answer('templates.constraints.get', self::HTTP_OK, $answer);

        $constraints = $client->pixonaut->templates()->getConstraints('tshirt-basic@2', 'blank_tshirt', 'front');

        self::assertSame([], $client->fake()->violations());
        self::assertSame(20.0, $constraints->placement?->foldMm);
    }

    public function testVariantsOnAnAxisOfAnyNameAreRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.variants.list');
        $answer->axes = [...self::values($answer->axes), 'Paper'];
        $answer->options = [...self::items($answer->options), (object) ['appearances' => (object) ['Matte' => null], 'name' => 'Paper', 'tones' => (object) ['Matte' => 'light'], 'values' => ['Matte']]];
        foreach (self::items($answer->variants) as $variant) {
            self::obj($variant->options)->Paper = 'Matte';
        }
        $client->fake()->answer('blanks.variants.list', self::HTTP_OK, $answer);

        $variants = $client->pixonaut->blanks()->listVariants('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        self::assertSame(['Color', 'Size', 'Paper'], $variants->axes);
        self::assertSame('Matte', $variants->variants[0]->options['Paper']);
    }

    public function testAFrameOptionsTexturesAreRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.variants.list');
        $answer->axes = [...self::values($answer->axes), 'Frame'];
        $answer->options = [...self::items($answer->options), (object) [
            'appearances' => (object) ['Oak' => null, 'White' => null],
            'name' => 'Frame',
            'textures' => (object) ['Oak' => 'oak', 'White' => 'paint'],
            'tones' => (object) ['Oak' => 'dark', 'White' => 'light'],
            'values' => ['Oak', 'White'],
        ]];
        foreach (self::items($answer->variants) as $variant) {
            self::obj($variant->options)->Frame = 'Oak';
        }
        $client->fake()->answer('blanks.variants.list', self::HTTP_OK, $answer);

        $variants = $client->pixonaut->blanks()->listVariants('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        $frame = array_values(array_filter($variants->options, static fn(object $option): bool => $option->name === 'Frame'));
        self::assertCount(1, $frame);
        self::assertSame(['Oak' => 'oak', 'White' => 'paint'], $frame[0]->textures);
        self::assertNull($variants->options[0]->textures);
    }

    public function testAViewRenderedFromAFoldingBoxsFlatLayoutIsRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.get');
        self::withPhotoSources($answer);
        $view = self::items(self::items($answer->placements)[0]->views)[0];
        $view->uvPasses = (object) [
            'fromFlatLayout' => (object) ['angle' => 'three_quarter', 'layoutKey' => self::FLAT_LAYOUT_KEY],
            'multiplyAssetId' => 'asset_box_multiply',
            'pieces' => [1, self::LAST_PIECE],
            'screenAssetId' => 'asset_box_screen',
            'uvAssetId' => 'asset_box_uv',
        ];
        $client->fake()->answer('blanks.get', self::HTTP_OK, $answer);

        $blank = $client->pixonaut->blanks()->get('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        $uvPasses = $blank->placements[0]->views[0]->uvPasses;
        self::assertSame('three_quarter', $uvPasses?->fromFlatLayout?->angle);
        self::assertSame(self::FLAT_LAYOUT_KEY, $uvPasses->fromFlatLayout->layoutKey);
        self::assertSame([1, self::LAST_PIECE], $uvPasses->pieces);
        self::assertNull($blank->placements[0]->views[1]->uvPasses?->fromFlatLayout);
    }

    public function testAPieceBeyondTheLastAUvPassMarksIsNotAnAnswerTheSpecAllows(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.get');
        $view = self::items(self::items($answer->placements)[0]->views)[0];
        $view->uvPasses = (object) [
            'multiplyAssetId' => 'asset_box_multiply',
            'pieces' => [self::LAST_PIECE + 1],
            'screenAssetId' => 'asset_box_screen',
            'uvAssetId' => 'asset_box_uv',
        ];
        $client->fake()->answer('blanks.get', self::HTTP_OK, $answer);

        $client->pixonaut->blanks()->get('blank_tshirt');

        self::assertNotSame([], $client->fake()->violations());
    }

    public function testMockupViewsEachWithTheirSourceAreRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('blanks.mockups.list');
        $placement = self::items($answer->placements)[0];
        foreach (self::items($placement->views) as $position => $view) {
            $view->source = $position === 0 ? 'stock' : 'own';
            $view->position = $position;
        }
        $placement->foldMm = 20;
        $client->fake()->answer('blanks.mockups.list', self::HTTP_OK, $answer);

        $mockups = $client->pixonaut->blanks()->listMockups('blank_tshirt');

        self::assertSame([], $client->fake()->violations());
        self::assertSame('stock', $mockups->placements[0]->views[0]->source);
    }

    /**
     * `$blank` with a second view on its first placement, from a scene, and a
     * print area on that placement; the placement and the area fold, and the
     * placement prints by a method the SDK does not know.
     */
    private static function withPhotoSources(stdClass $blank): void
    {
        unset($blank->photoSource, $blank->scenes);
        $placement = self::items($blank->placements)[0];
        $views = self::items($placement->views);
        $view = clone $views[0];
        $view->id = 'view-kitchen';
        $view->label = 'Kitchen';
        $view->source = 'stock';
        $view->position = 1;
        $placement->views = [...$views, $view];
        $placement->foldMm = 20;
        $placement->printMethod = self::UNKNOWN_PRINT_METHOD;
        $placement->areas = [(object) [
            'foldMm' => 15,
            'heightMm' => 360,
            'row' => (object) [],
            'usualHeightMm' => 360,
            'usualWidthMm' => 280,
            'widthMm' => 280,
        ]];
    }

    private static function obj(mixed $value): stdClass
    {
        self::assertInstanceOf(stdClass::class, $value);

        return $value;
    }

    /**
     * @return list<stdClass>
     */
    private static function items(mixed $value): array
    {
        return array_map(self::obj(...), self::values($value));
    }

    /**
     * @return list<mixed>
     */
    private static function values(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        return $value;
    }

    /**
     * A copy of the spec's success example for `$operation`.
     */
    private static function example(string $operation): stdClass
    {
        $example = Spec::pinned()->operation($operation)->answerExample(self::HTTP_OK);
        $copy = json_decode(json_encode($example, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $copy);

        return $copy;
    }
}
