<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Spec;

use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Generator\Spec\Cdn;
use Pixonaut\Sdk\Generator\Spec\SpecCommand;
use Pixonaut\Sdk\Generator\Spec\SpecFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecCommandTest extends TestCase
{
    private const PINNED_FILES = ['openapi.json', 'webhook-signature-vectors.json', 'spec.lock.json'];

    private const PINNED_FILES_SORTED = ['openapi.json', 'spec.lock.json', 'webhook-signature-vectors.json'];

    private const VECTORS = '{"vectors":[]}';

    private string $directory;

    private FakeCdn $cdn;

    /** @var list<string> */
    private array $output = [];

    /** @var list<string> Directories `localReference()` made. */
    private array $sources = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/spec-' . bin2hex(random_bytes(8));
        $this->cdn = new FakeCdn();
        $this->cdn->publish('1.0.0', self::specJson('1.0.0'), self::VECTORS);
        $this->cdn->publish('1.1.0', self::specJson('1.1.0'), self::VECTORS);
    }

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $file) {
                unlink($source . '/' . $file);
            }
            rmdir($source);
        }
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            unlink($this->directory . '/' . $file);
        }
        rmdir($this->directory);
    }

    public function testPinWritesTheSpecTheVectorsAndTheLock(): void
    {
        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('pin', '1.0.0'));

        self::assertSame(self::specJson('1.0.0'), $this->read('openapi.json'));
        self::assertSame(self::VECTORS, $this->read('webhook-signature-vectors.json'));
        self::assertSame([
            'version' => '1.0.0',
            'baseUrl' => 'https://cdn.pixonaut.app/api/1.0.0/',
            'sha256' => hash('sha256', self::specJson('1.0.0')),
            'webhookSignatureVectors' => ['sha256' => hash('sha256', self::VECTORS)],
        ], json_decode($this->read('spec.lock.json'), true));
        self::assertSame(self::PINNED_FILES_SORTED, $this->listing());
    }

    public function testPinningThePinnedReleaseAgainChangesNoByte(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('pin'));

        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('already pinned', $this->lastLine());
    }

    public function testAFileThatDoesNotMatchItsIntegrityLeavesThePinIntact(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();
        $this->cdn->serve('1.1.0', 'openapi.json', self::specJson('1.1.0') . ' ');

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '1.1.0'));

        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('does not match its integrity hash', $this->lastLine());
    }

    public function testAnUnpublishedReleaseLeavesThePinIntact(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '2.0.0'));

        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('API 2.0.0 is not published', $this->lastLine());
    }

    public function testAFileThatAnswersAnErrorLeavesThePinIntact(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();
        $this->cdn->serve('1.1.0', 'webhook-signature-vectors.json', '{}', 503);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '1.1.0'));

        self::assertSame($before, $this->snapshot());
        self::assertStringContainsString('answered 503', $this->lastLine());
    }

    public function testAFailedReplacementPutsBackTheFilesAlreadyReplaced(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();
        // The vectors cannot be moved aside, after the spec was replaced.
        $blocked = $this->directory . '/webhook-signature-vectors.json.previous';
        mkdir($blocked);
        touch($blocked . '/occupied');

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '1.1.0'));

        unlink($blocked . '/occupied');
        rmdir($blocked);
        self::assertSame($before, $this->snapshot());
        self::assertSame(self::PINNED_FILES_SORTED, $this->listing());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $this->cdn->publish('1.0.0', '<html>maintenance</html>', self::VECTORS);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '1.0.0'));

        self::assertDirectoryDoesNotExist($this->directory);
        self::assertStringContainsString('did not answer JSON', $this->lastLine());
    }

    public function testASpecDeclaringAnotherVersionIsRefused(): void
    {
        $this->cdn->publish('1.2.0', self::specJson('1.1.0'), self::VECTORS);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '1.2.0'));

        self::assertDirectoryDoesNotExist($this->directory);
        self::assertStringContainsString('declares info.version 1.1.0', $this->lastLine());
    }

    public function testAVersionThatIsNotAReleaseIsRefusedBeforeAnyRequest(): void
    {
        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '../1.0.0'));

        self::assertSame([], $this->cdn->requested);
    }

    public function testPinningAnotherVersionKeepsThePreviousSpecForTheDiff(): void
    {
        $this->binSpec('pin', '1.0.0');
        $previous = $this->directory . '/previous.json';

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('pin', '1.1.0', '--previous-out', $previous));

        self::assertSame(self::specJson('1.0.0'), file_get_contents($previous));
        self::assertSame(self::specJson('1.1.0'), $this->read('openapi.json'));
        self::assertSame('Pinned API 1.1.0 (was 1.0.0).', $this->lastLine());
    }

    public function testPinningTheSameVersionKeepsNoPreviousSpec(): void
    {
        $this->binSpec('pin', '1.0.0');
        $previous = $this->directory . '/previous.json';

        $this->binSpec('pin', '--previous-out', $previous);

        self::assertFileDoesNotExist($previous);
    }

    public function testPinningWithNothingPinnedNeedsAVersion(): void
    {
        self::assertSame(SpecCommand::EXIT_USAGE, $this->binSpec('pin'));
    }

    public function testCheckPassesOnAnIntactPin(): void
    {
        $this->binSpec('pin', '1.0.0');
        $this->cdn->requested = [];

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('check'));
        self::assertSame([], $this->cdn->requested, 'check without --online stays offline');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pinnedData(): iterable
    {
        yield 'the spec' => ['openapi.json'];
        yield 'the vectors' => ['webhook-signature-vectors.json'];
    }

    #[DataProvider('pinnedData')]
    public function testCheckFailsOnAOneByteEdit(string $file): void
    {
        $this->binSpec('pin', '1.0.0');
        $bytes = $this->read($file);
        $edited = substr_replace($bytes, ' ', -1, 1);
        file_put_contents($this->directory . '/' . $file, $edited);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('check'));
        self::assertStringContainsString("{$file} does not match the sha256", implode("\n", $this->output));
    }

    public function testCheckFailsWhenTheLockNamesAnotherVersionThanTheSpec(): void
    {
        $this->binSpec('pin', '1.0.0');
        $lock = str_replace('1.0.0', '1.1.0', $this->read('spec.lock.json'));
        file_put_contents($this->directory . '/spec.lock.json', $lock);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('check'));
        self::assertStringContainsString('pins 1.1.0, but openapi.json declares info.version 1.0.0', implode("\n", $this->output));
    }

    public function testCheckFailsWithNothingPinned(): void
    {
        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('check'));
    }

    public function testOnlineCheckPassesWhenTheCdnServesThePinnedRelease(): void
    {
        $this->binSpec('pin', '1.0.0');

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('check', '--online'));
        self::assertContains(FakeCdn::ORIGIN . '/api/1.0.0/openapi.json', $this->cdn->requested);
    }

    public function testOnlineCheckFailsWhenTheCdnServesSomethingElse(): void
    {
        $this->binSpec('pin', '1.0.0');
        $this->cdn->publish('1.0.0', self::specJson('1.0.0', 'changed'), self::VECTORS);

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('check', '--online'));
        self::assertStringContainsString('differ from API 1.0.0 on the CDN', implode("\n", $this->output));
    }

    public function testPinFromADirectoryPinsItsReferenceMarkedUnpublished(): void
    {
        $this->binSpec('pin', '1.0.0');
        $source = $this->localReference(self::specJson('1.0.0', 'ahead'));
        $previous = $this->directory . '/previous.json';

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('pin', '--from', $source, '--previous-out', $previous));

        self::assertSame(self::specJson('1.0.0', 'ahead'), $this->read('openapi.json'));
        self::assertSame(self::specJson('1.0.0'), file_get_contents($previous));
        $lock = json_decode($this->read('spec.lock.json'), true);
        self::assertIsArray($lock);
        self::assertSame(hash('sha256', self::specJson('1.0.0', 'ahead')), $lock['sha256']);
        self::assertFalse($lock['published']);
        self::assertStringContainsString('unpublished API 1.0.0', $this->lastLine());
        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('check'));
    }

    public function testOnlineCheckFailsOnAnUnpublishedPin(): void
    {
        $this->binSpec('pin', '--from', $this->localReference(self::specJson('1.0.0', 'ahead')));

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('check', '--online'));
        self::assertStringContainsString('unpublished API 1.0.0 reference', implode("\n", $this->output));
    }

    public function testPinningThePublishedReleaseAgainDropsTheUnpublishedMark(): void
    {
        $this->binSpec('pin', '--from', $this->localReference(self::specJson('1.0.0', 'ahead')));

        self::assertSame(SpecCommand::EXIT_OK, $this->binSpec('pin', '1.0.0'));

        self::assertArrayNotHasKey('published', (array) json_decode($this->read('spec.lock.json'), true));
    }

    public function testPinFromADirectoryRefusesAVersion(): void
    {
        self::assertSame(SpecCommand::EXIT_USAGE, $this->binSpec('pin', '1.0.0', '--from', $this->localReference(self::specJson('1.0.0'))));
    }

    public function testPinFromADirectoryWithoutTheVectorsLeavesThePinIntact(): void
    {
        $this->binSpec('pin', '1.0.0');
        $before = $this->snapshot();
        $source = $this->localReference(self::specJson('1.0.0', 'ahead'));
        unlink($source . '/webhook-signature-vectors.json');

        self::assertSame(SpecCommand::EXIT_FAILED, $this->binSpec('pin', '--from', $source));
        self::assertSame($before, $this->snapshot());
    }

    /**
     * A directory beside the pinned one holding `$spec` and the vectors,
     * which tearDown removes.
     */
    private function localReference(string $spec): string
    {
        $source = $this->directory . '-source';
        if (!is_dir($source)) {
            mkdir($source, 0o777, true);
        }
        file_put_contents($source . '/openapi.json', $spec);
        file_put_contents($source . '/webhook-signature-vectors.json', self::VECTORS);
        $this->sources[] = $source;

        return $source;
    }

    private function binSpec(string ...$arguments): int
    {
        $this->output = [];
        $command = new SpecCommand(
            new SpecFiles($this->directory),
            fn(string $origin): Cdn => new Cdn($this->cdn, new Psr17Factory(), $origin),
            function (string $line): void {
                $this->output[] = $line;
            },
        );

        return $command->run([...array_values($arguments), '--cdn', FakeCdn::ORIGIN]);
    }

    private function read(string $file): string
    {
        $bytes = file_get_contents($this->directory . '/' . $file);
        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];
        foreach (self::PINNED_FILES as $file) {
            $files[$file] = $this->read($file);
        }

        return $files;
    }

    /**
     * @return list<string> The directory's entries, sorted.
     */
    private function listing(): array
    {
        return array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
    }

    private function lastLine(): string
    {
        return $this->output[array_key_last($this->output) ?? 0] ?? '';
    }

    private static function specJson(string $version, string $title = 'Pixonaut'): string
    {
        return json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => $version],
            'paths' => new \stdClass(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
