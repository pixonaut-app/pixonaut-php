<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Spec;

use RuntimeException;

/**
 * A pin or a check that cannot go on: the message says why, for the person
 * running `bin/spec`.
 */
final class SpecException extends RuntimeException {}
