<?php

declare(strict_types=1);

// Commits the design a shopper made, once, even when the answer is lost on
// the way back and the commit is sent again later.

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

// The proof token the designer handed the cart, and the cart line it is for.
$proofToken = 'proof_1';
$cartLine = 'cart-10482-1';

// One key per design to commit: a key derived from what it commits makes a
// second attempt the same request rather than a second design.
$prepared = $pixonaut->designs()->prepareCommit(
    new DesignsCommitRequestOption1(proofToken: $proofToken, externalRef: $cartLine),
    idempotencyKey: 'commit-' . $proofToken,
);

// Store it before sending: it is everything needed to send the same request
// again, key included. Here it goes to a file; a host keeps it with the
// cart line it commits.
$snapshot = json_encode($prepared->toArray(), JSON_THROW_ON_ERROR);
file_put_contents(sys_get_temp_dir() . "/pixonaut-commit-{$cartLine}.json", $snapshot);

try {
    $design = $pixonaut->designs()->sendCommit($prepared);
} catch (ApiException $e) {
    if (!$e->retryable) {
        throw $e;
    }
    // Later, from a scheduled job: send the stored request again. Pixonaut
    // answers a key it has seen with the first answer, so the design is
    // committed once.
    $stored = PreparedRequest::fromArray((array) json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR));
    $design = $pixonaut->designs()->sendCommit($stored);
}

echo "Committed design {$design->designId}.\n";
