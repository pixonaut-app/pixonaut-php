<?php

declare(strict_types=1);

/*
 * The fake served over HTTP, for PHP's built-in server:
 *
 *     FAKE_PIXONAUT_STATE=<file> php -S 127.0.0.1:<port> tests/Fake/router.php
 *
 * Each request runs in a fresh process, so what the fake remembers (keys
 * answered, violations, the log) lives in the state file between them,
 * where `FakeServer` scripts and reads it.
 */

use Nyholm\Psr7\Request;
use Pixonaut\Sdk\Tests\Fake\FakeNetworkException;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeStateFile;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../../vendor/autoload.php';

/** What a dropped answer promises and never sends. */
const PROMISED_BYTES = 1;

$file = getenv('FAKE_PIXONAUT_STATE');
if (!is_string($file) || $file === '') {
    http_response_code(500);
    echo 'FAKE_PIXONAUT_STATE names no state file.';

    return;
}
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$target = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$headers = [];
foreach ((array) getallheaders() as $name => $value) {
    $headers[(string) $name] = is_scalar($value) ? (string) $value : '';
}
$request = new Request($method, $target, $headers, (string) file_get_contents('php://input'));

try {
    $response = (new FakeStateFile($file))->update(static fn(FakePixonaut $fake): ResponseInterface => $fake->sendRequest($request));
} catch (FakeNetworkException) {
    // The connection drops mid-answer: a body is promised and never sent,
    // so the client sees a transfer cut short rather than an answer.
    header('Content-Length: ' . PROMISED_BYTES);

    return;
}

http_response_code($response->getStatusCode());
header_remove('X-Powered-By');
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
