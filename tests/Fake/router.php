<?php

declare(strict_types=1);

/*
 * The fake served over HTTP, for PHP's built-in server:
 *
 *     FAKE_PIXONAUT_STATE=<file> php -S 127.0.0.1:<port> tests/Fake/router.php
 *
 * Each request runs in a fresh process, so what the fake remembers (keys
 * answered, violations, the log) lives in the state file between them,
 * where `FakeServer::state()` reads it.
 */

use Nyholm\Psr7\Request;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\FakeState;
use Pixonaut\Sdk\Tests\Fake\Fault;

require __DIR__ . '/../../vendor/autoload.php';

$file = getenv('FAKE_PIXONAUT_STATE');
if (!is_string($file) || $file === '') {
    http_response_code(500);
    echo 'FAKE_PIXONAUT_STATE names no state file.';

    return;
}
$handle = fopen($file, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    http_response_code(500);
    echo 'The state file cannot be opened.';

    return;
}
$stored = (string) stream_get_contents($handle);
$state = $stored === '' ? new FakeState() : unserialize($stored, ['allowed_classes' => [FakeState::class, Fault::class]]);
if (!$state instanceof FakeState) {
    $state = new FakeState();
}
$fake = new FakePixonaut($state);

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$target = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$headers = [];
foreach ((array) getallheaders() as $name => $value) {
    $headers[(string) $name] = is_scalar($value) ? (string) $value : '';
}
$response = $fake->sendRequest(new Request($method, $target, $headers, (string) file_get_contents('php://input')));

ftruncate($handle, 0);
rewind($handle);
fwrite($handle, serialize($fake->state()));
flock($handle, LOCK_UN);
fclose($handle);

http_response_code($response->getStatusCode());
header_remove('X-Powered-By');
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
