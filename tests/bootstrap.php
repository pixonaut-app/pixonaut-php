<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// No test reaches a host other than this machine's loopback.
Pixonaut\Sdk\Tests\NetworkGuard::install();
