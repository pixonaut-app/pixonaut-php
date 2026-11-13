<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateRequestOption1;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateRequestOption2;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateRequestOption2SizesItem;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateResponseFalse;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateResponseOption3;
use Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateResponseOption3VerdictsItemTrue;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;

/**
 * Print-size verdicts as the API answers them: each names the images that
 * print below their recommended resolution at the size, and a size can be
 * refused for that alone. Every answer is one the spec allows (the fake
 * checks it), and the SDK reads it.
 */
final class PrintSizeAnswersTest extends TestCase
{
    private const HTTP_OK = 200;

    private const TEMPLATE_ID = 'tpl_poster';

    /** A size the template's image is too small for. */
    private const LARGE_SIDE_MM = 600.0;

    /** A size the template's image covers at its recommended resolution. */
    private const SMALL_SIDE_MM = 100.0;

    /** Pixels per inch the image reaches at the large size. */
    private const EFFECTIVE_DPI = 96.0;

    /** The resolution the image is judged against. */
    private const RECOMMENDED_DPI = 150.0;

    public function testASizeRefusedForALowResolutionImageNamesTheImage(): void
    {
        $client = new ContractClient();
        $client->fake()->answer('templates.printSize.validate', self::HTTP_OK, [
            'legal' => false,
            'reason' => 'low_resolution',
            'lowResolution' => [self::lowResolutionImage()],
        ]);

        $verdict = $client->pixonaut->templates()->validatePrintSize(
            self::TEMPLATE_ID,
            new TemplatesPrintSizeValidateRequestOption1(heightMm: self::LARGE_SIDE_MM, widthMm: self::LARGE_SIDE_MM),
        );

        self::assertSame([], $client->fake()->violations());
        self::assertInstanceOf(TemplatesPrintSizeValidateResponseFalse::class, $verdict);
        self::assertSame('low_resolution', $verdict->reason);
        self::assertCount(1, $verdict->lowResolution);
        $image = $verdict->lowResolution[0];
        self::assertSame('ast_photo', $image->assetId);
        self::assertSame('layer_photo', $image->layerId);
        self::assertSame('Photo', $image->label);
        self::assertSame(self::EFFECTIVE_DPI, $image->effectiveDpi);
        self::assertSame(self::RECOMMENDED_DPI, $image->recommendedDpi);
    }

    public function testEachVerdictOfSeveralSizesNamesItsOwnLowResolutionImages(): void
    {
        $client = new ContractClient();
        $client->fake()->answer('templates.printSize.validate', self::HTTP_OK, [
            'verdicts' => [
                ['legal' => true, 'lowResolution' => []],
                ['legal' => true, 'lowResolution' => [self::lowResolutionImage()]],
            ],
        ]);

        $answer = $client->pixonaut->templates()->validatePrintSize(
            self::TEMPLATE_ID,
            new TemplatesPrintSizeValidateRequestOption2(sizes: [
                new TemplatesPrintSizeValidateRequestOption2SizesItem(heightMm: self::SMALL_SIDE_MM, widthMm: self::SMALL_SIDE_MM),
                new TemplatesPrintSizeValidateRequestOption2SizesItem(heightMm: self::LARGE_SIDE_MM, widthMm: self::LARGE_SIDE_MM),
            ]),
        );

        self::assertSame([], $client->fake()->violations());
        self::assertInstanceOf(TemplatesPrintSizeValidateResponseOption3::class, $answer);
        self::assertContainsOnlyInstancesOf(TemplatesPrintSizeValidateResponseOption3VerdictsItemTrue::class, $answer->verdicts);
        self::assertSame([0, 1], array_map(static fn(object $verdict): int => count($verdict->lowResolution), $answer->verdicts));
    }

    /**
     * @return array<string, float|string>
     */
    private static function lowResolutionImage(): array
    {
        return [
            'layerId' => 'layer_photo',
            'assetId' => 'ast_photo',
            'label' => 'Photo',
            'effectiveDpi' => self::EFFECTIVE_DPI,
            'recommendedDpi' => self::RECOMMENDED_DPI,
        ];
    }
}
