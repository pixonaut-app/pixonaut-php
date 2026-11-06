<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface\Fixtures;

enum Unit: string
{
    case Metre = 'm';
    case Inch = 'in';

    public const DEFAULT = self::Metre;

    public function label(): string
    {
        return $this->value;
    }
}
