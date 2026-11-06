<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documentation shows the examples `ExamplesTest` runs, byte for byte,
 * and links only to files that exist.
 */
final class DocumentationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const QUICK_START = self::ROOT . '/docs/quick-start.md';

    private const EXAMPLES = self::ROOT . '/docs/examples';

    /** `<!-- example: client.php -->` and the PHP block that follows it. */
    private const SNIPPET = '/<!-- example: (\S+) -->\n```php\n(.*?)\n```/s';

    /** A relative Markdown link's target, anchors aside. */
    private const RELATIVE_LINK = '/\]\((?!https?:|#)([^)#\s]+)(?:#[^)]*)?\)/';

    public function testTheQuickStartShowsEveryExampleAsItIs(): void
    {
        preg_match_all(self::SNIPPET, (string) file_get_contents(self::QUICK_START), $snippets, PREG_SET_ORDER);

        $shown = [];
        foreach ($snippets as [, $file, $code]) {
            self::assertFileExists(self::EXAMPLES . '/' . $file);
            self::assertSame(rtrim((string) file_get_contents(self::EXAMPLES . '/' . $file), "\n"), $code, $file . ' differs from its snippet in docs/quick-start.md.');
            $shown[] = $file;
        }
        $examples = array_map(static fn(string $path): string => basename($path), glob(self::EXAMPLES . '/*.php') ?: []);
        sort($examples);
        sort($shown);
        self::assertSame($examples, $shown, 'docs/quick-start.md shows each example once.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        foreach ([self::ROOT . '/README.md', self::ROOT . '/CONTRIBUTING.md', ...(array) glob(self::ROOT . '/docs/*.md')] as $document) {
            yield basename((string) $document) => [(string) $document];
        }
    }

    #[DataProvider('documents')]
    public function testRelativeLinksPointAtFiles(string $document): void
    {
        preg_match_all(self::RELATIVE_LINK, (string) file_get_contents($document), $links);

        foreach ($links[1] as $link) {
            self::assertFileExists(dirname($document) . '/' . $link, basename($document) . ' links to a missing ' . $link . '.');
        }
        $this->addToAssertionCount(1);
    }
}
