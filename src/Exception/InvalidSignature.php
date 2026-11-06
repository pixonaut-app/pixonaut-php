<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Exception;

use RuntimeException;

/**
 * A webhook delivery that was not signed with the endpoint's secret, or was
 * signed too long ago to be accepted. Answer it with a 4xx and do not act on
 * its body.
 */
final class InvalidSignature extends RuntimeException {}
