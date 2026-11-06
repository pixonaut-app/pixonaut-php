<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Model;

/**
 * The default of an optional request field that may also be `null`: the
 * field is left out of the request, where `null` sends `null`.
 */
enum Omitted
{
    case Field;
}
