<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

use RuntimeException;

/**
 * `bin/spec` was called with arguments it does not take.
 */
final class UsageException extends RuntimeException {}
