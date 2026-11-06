<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface\Fixtures;

final class Square extends Polygon implements Shape, \Stringable
{
    use Named;

    public const SIDES = 4;

    private const HIDDEN = 'not on the surface';

    public static int $made = 0;

    public function __construct(
        public readonly float $side,
        public readonly Unit $unit = Unit::Metre,
        private readonly string $label = self::HIDDEN,
    ) {}

    public function area(): float
    {
        return $this->side ** 2;
    }

    public function count(): int
    {
        return self::SIDES;
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function scaled(float|int $by, string ...$notes): self
    {
        return new self($this->side * $by);
    }

    public static function of(int|string|null $side = null, array $tags = ['a' => 1]): static
    {
        return new static((float) $side);
    }

    protected function hidden(): void {}
}
