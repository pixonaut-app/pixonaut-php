<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Release;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\ApiVersion;
use Pixonaut\Sdk\Generator\Release\ReleaseNotes;
use UnexpectedValueException;

final class ReleaseNotesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const RELEASE_PULL_REQUEST = <<<'MD'
        :robot: I have created a release *beep* *boop*
        ---


        ## [1.0.0](https://github.com/pixonaut-app/pixonaut-php/compare/v0.0.0...v1.0.0) (2026-11-06)


        ### Features

        * add the PSR-18 client core ([#12](https://github.com/pixonaut-app/pixonaut-php/issues/12)) ([3e2738d](https://github.com/pixonaut-app/pixonaut-php/commit/3e2738d))

        ---
        This PR was generated with [Release Please](https://github.com/googleapis/release-please).
        MD;

    public function testTheSdkAndTheComposerFileNameTheSameApiRelease(): void
    {
        $apiVersion = ReleaseNotes::apiVersion((string) file_get_contents(self::ROOT . '/composer.json'));

        self::assertSame(ApiVersion::TARGET, $apiVersion, 'composer.json extra.pixonaut.api-version is not the API release the SDK is generated from.');
    }

    /**
     * The newest entry names the API of the last published release, which
     * a pull request pinning a newer API rightly leaves alone: only its
     * shape is checked.
     */
    public function testTheNewestChangelogEntryNamesAnApiRelease(): void
    {
        if (!is_file(self::ROOT . '/CHANGELOG.md')) {
            $this->markTestSkipped('No release yet.');
        }

        self::assertNotNull(
            ReleaseNotes::newestApiVersion((string) file_get_contents(self::ROOT . '/CHANGELOG.md')),
            'The newest CHANGELOG.md entry does not open with the API release it targets.',
        );
    }

    public function testTheNewestEntryNamesItsOwnApiReleaseWhateverTheSdkTargets(): void
    {
        $changelog = "# Changelog\n\n## [1.0.0](https://example.test) (2026-11-06)\n\nTargets the Pixonaut API 1.0.0.\n\n### Features\n\n* first\n";

        self::assertSame('1.0.0', ReleaseNotes::newestApiVersion($changelog));
        self::assertSame('2.3.4', ReleaseNotes::newestApiVersion(ReleaseNotes::annotate(self::RELEASE_PULL_REQUEST, '2.3.4')));
        self::assertNull(ReleaseNotes::newestApiVersion(self::RELEASE_PULL_REQUEST));
        self::assertNull(ReleaseNotes::newestApiVersion("### Features\n"));
    }

    public function testTheLineOpensTheNewestVersionsNotes(): void
    {
        $annotated = ReleaseNotes::annotate(self::RELEASE_PULL_REQUEST, '1.0.0');

        self::assertStringContainsString("(2026-11-06)\n\nTargets the Pixonaut API 1.0.0.\n\n### Features\n", $annotated);
        self::assertSame('Targets the Pixonaut API 1.0.0.', ReleaseNotes::newestFirstLine($annotated));
        self::assertStringStartsWith(":robot: I have created a release *beep* *boop*\n---\n", $annotated);
        self::assertStringEndsWith("This PR was generated with [Release Please](https://github.com/googleapis/release-please).", $annotated);
    }

    public function testNotesThatOpenWithTheLineAreLeftAsTheyAre(): void
    {
        $once = ReleaseNotes::annotate(self::RELEASE_PULL_REQUEST, '1.0.0');

        self::assertSame($once, ReleaseNotes::annotate($once, '1.0.0'));
    }

    public function testOnlyTheNewestEntryOfAChangelogIsAnnotated(): void
    {
        $changelog = "# Changelog\n\n## [1.1.0](https://example.test) (2027-01-01)\n\n### Features\n\n* more\n\n## [1.0.0](https://example.test) (2026-11-06)\n\nTargets the Pixonaut API 1.0.0.\n\n### Features\n\n* first\n";

        $annotated = ReleaseNotes::annotate($changelog, '1.1.0');

        self::assertSame(
            "# Changelog\n\n## [1.1.0](https://example.test) (2027-01-01)\n\nTargets the Pixonaut API 1.1.0.\n\n### Features\n\n* more\n\n## [1.0.0](https://example.test) (2026-11-06)\n\nTargets the Pixonaut API 1.0.0.\n\n### Features\n\n* first\n",
            $annotated,
        );
    }

    public function testNotesWithoutAHeadingOpenWithTheLine(): void
    {
        self::assertSame("Targets the Pixonaut API 1.0.0.\n\n### Features\n", ReleaseNotes::annotate("### Features\n", '1.0.0'));
        self::assertSame("Targets the Pixonaut API 1.0.0.\n", ReleaseNotes::annotate('', '1.0.0'));
        self::assertNull(ReleaseNotes::newestFirstLine("### Features\n"));
    }

    public function testAComposerFileWithoutTheApiVersionIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ReleaseNotes::apiVersion('{"name":"pixonaut/pixonaut-php"}');
    }
}
