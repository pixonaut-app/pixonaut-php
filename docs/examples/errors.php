<?php

declare(strict_types=1);

// What a failed call tells the host, and which failures to try again.

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\IdempotencyBugException;
use Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

try {
    $template = $pixonaut->templates()->get('tshirt-basic@2');
    echo "Read {$template->template->name}.\n";
} catch (IdempotencyBugException $e) {
    // The request was built wrong: a key reused for another request, or one
    // Pixonaut does not accept. Sending it again never helps; fix the code.
    error_log("Pixonaut refused the idempotency key: {$e->getMessage()}");
} catch (ApiException $e) {
    if ($e->errorCode === ErrorCode::NOT_FOUND) {
        echo "No such template.\n";
    } elseif ($e->retryable) {
        // Pixonaut was busy or out of reach: try again later, no sooner than
        // it asked (`Retry-After`), with a backoff of your own when it did
        // not say.
        $backoffSeconds = 30;
        $wait = $e->retryAfter ?? $backoffSeconds;
        echo "Try again in {$wait} seconds ({$e->errorCode}).\n";
    } else {
        // Refused for good: show the code, and keep the request id for support.
        echo "Pixonaut refused it: {$e->errorCode} (request {$e->requestId}).\n";
    }
}
