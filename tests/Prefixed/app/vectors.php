<?php

declare(strict_types=1);

/*
 * The spec's webhook signature vectors, checked with the copy of the SDK
 * this file is prefixed with:
 *
 *     php -d auto_prepend_file=<autoload> vectors.php <webhook-signature-vectors.json>
 *
 * Prints one line per vector and exits non-zero when any is decided wrong.
 */

use Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\Sdk\Webhooks\Signature;

const EXIT_OK = 0;
const EXIT_WRONG = 1;
const EXIT_USAGE = 2;

/** @var list<string> $argv */
if (count($argv) !== 2) {
    fwrite(STDERR, "Usage: vectors.php <webhook-signature-vectors.json>\n");
    exit(EXIT_USAGE);
}
/** @var array{now: int, valid: list<array{name: string, secret: string, body: string, headers: array<string, string>}>, invalid: list<array{name: string, secret: string, body: string, headers: array<string, string>}>} $vectors */
$vectors = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$wrong = 0;
foreach (['valid' => true, 'invalid' => false] as $set => $expected) {
    foreach ($vectors[$set] as $vector) {
        $verified = Signature::verify(
            $vector['secret'],
            $vector['headers'][Signature::TIMESTAMP_HEADER] ?? '',
            $vector['headers'][Signature::SIGNATURE_HEADER] ?? '',
            $vector['body'],
            $vectors['now'],
        );
        if ($verified && $expected) {
            Event::fromJson($vector['body'])->typed();
        }
        $wrong += $verified === $expected ? 0 : 1;
        echo ($verified === $expected ? 'ok ' : 'WRONG ') . $set . ': ' . $vector['name'] . "\n";
    }
}

exit($wrong === 0 ? EXIT_OK : EXIT_WRONG);
