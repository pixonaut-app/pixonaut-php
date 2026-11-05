<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use RuntimeException;

/**
 * A spec the generator cannot turn into code: the message says where and why.
 */
final class GeneratorException extends RuntimeException {}
