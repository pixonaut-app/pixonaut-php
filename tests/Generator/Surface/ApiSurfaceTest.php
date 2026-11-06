<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generator\Surface\ApiSurface;

final class ApiSurfaceTest extends TestCase
{
    public function testListsEveryPublicClassConstantPropertyAndMethodSignature(): void
    {
        $surface = (new ApiSurface(__DIR__ . '/Fixtures', __NAMESPACE__ . '\\Fixtures'))->render();

        $f = __NAMESPACE__ . '\\Fixtures';
        self::assertSame(
            <<<TXT
                trait {$f}\\Named
                {$f}\\Named::name(): string
                abstract class {$f}\\Polygon
                {$f}\\Polygon::area(): float abstract
                {$f}\\Polygon::describe(?{$f}\\Unit &\$unit): string final
                interface {$f}\\Shape extends Countable
                {$f}\\Shape::SIDES const
                {$f}\\Shape::area(): float
                final class {$f}\\Square extends {$f}\\Polygon implements {$f}\\Shape, Stringable uses {$f}\\Named
                {$f}\\Square::\$made static int
                {$f}\\Square::\$side readonly float
                {$f}\\Square::\$unit readonly {$f}\\Unit
                {$f}\\Square::SIDES const
                {$f}\\Square::__construct(float \$side, {$f}\\Unit \$unit = {$f}\\Unit::Metre, string \$label = {$f}\\Square::HIDDEN)
                {$f}\\Square::__toString(): string
                {$f}\\Square::area(): float
                {$f}\\Square::count(): int
                {$f}\\Square::name(): string
                {$f}\\Square::of(int|null|string \$side = null, array \$tags = array ('a' => 1,)): static static
                {$f}\\Square::scaled(float|int \$by, string ...\$notes): {$f}\\Square
                enum {$f}\\Unit: string
                {$f}\\Unit::DEFAULT const
                {$f}\\Unit::Inch case = 'in'
                {$f}\\Unit::Metre case = 'm'
                {$f}\\Unit::label(): string

                TXT,
            $surface,
        );
    }

    public function testTheCommittedSurfaceIsTheSourcesSurface(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertSame(
            (string) file_get_contents($root . '/api-surface.txt'),
            (new ApiSurface($root . '/src', 'Pixonaut\\Sdk'))->render(),
            'api-surface.txt is stale: run bin/surface.',
        );
    }
}
