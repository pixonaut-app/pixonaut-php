<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Surface;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * The package's public surface, one line per class, constant, property and
 * method: what a caller can name. A line removed or changed between two
 * releases is a change a caller may have to follow, so it is a major.
 *
 * Classes marked `@internal` are left out. Constant values are left out
 * too, except an enum case's, since a release changes some (`VERSION`)
 * without changing what a caller can name. Types are written the same
 * way whatever the PHP version, union members sorted.
 */
final class ApiSurface
{
    private const INTERNAL = '/^\s*\*\s*@internal\b/m';

    /**
     * @param string $source    The directory the classes are in.
     * @param string $namespace The namespace `$source` maps to.
     */
    public function __construct(
        private readonly string $source,
        private readonly string $namespace,
    ) {}

    /**
     * The surface, one line each, sorted, ending in a newline.
     */
    public function render(): string
    {
        $lines = [];
        foreach ($this->classes() as $class) {
            array_push($lines, ...$this->describe(new ReflectionClass($class)));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Every class, interface, trait and enum under the source directory, by
     * name.
     *
     * @return list<class-string>
     */
    private function classes(): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->source, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($this->source) + 1, -strlen('.php'));
            $name = $this->namespace . '\\' . str_replace('/', '\\', $relative);
            if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
                $classes[] = $name;
            }
        }
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function describe(ReflectionClass $class): array
    {
        if (preg_match(self::INTERNAL, (string) $class->getDocComment()) === 1) {
            return [];
        }
        $name = $class->getName();
        $lines = [$this->declaration($class)];

        if ($class->isEnum()) {
            /** @var class-string<\UnitEnum> $name */
            $enum = new ReflectionEnum($name);
            foreach ($enum->getCases() as $case) {
                $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                $lines[] = sprintf('%s::%s case%s', $name, $case->getName(), $value);
            }
        }

        $constants = array_filter(
            $class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC),
            static fn(ReflectionClassConstant $constant): bool => $constant->getDeclaringClass()->getName() === $name && !$constant->isEnumCase(),
        );
        foreach ($constants as $constant) {
            $lines[] = sprintf('%s::%s %sconst', $name, $constant->getName(), $constant->isFinal() ? 'final ' : '');
        }

        $properties = array_filter(
            $class->getProperties(ReflectionProperty::IS_PUBLIC),
            // An enum's `name` and `value` are every enum's.
            static fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $name && !$class->isEnum(),
        );
        foreach ($properties as $property) {
            $lines[] = sprintf(
                '%s::$%s %s%s%s',
                $name,
                $property->getName(),
                $property->isStatic() ? 'static ' : '',
                $property->isReadOnly() ? 'readonly ' : '',
                self::type($property->getType(), $name),
            );
        }

        $methods = array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $name && !self::isEnumBuiltIn($class, $method),
        );
        foreach ($methods as $method) {
            $lines[] = $this->method($name, $method);
        }

        $head = array_shift($lines);
        sort($lines, SORT_STRING);

        return [$head, ...$lines];
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function declaration(ReflectionClass $class): string
    {
        $kind = match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            default => trim(($class->isAbstract() ? 'abstract ' : '') . ($class->isFinal() ? 'final ' : '') . 'class'),
        };
        $line = $kind . ' ' . $class->getName();
        if ($class->isEnum()) {
            /** @var class-string<\UnitEnum> $enumName */
            $enumName = $class->getName();
            $backing = (new ReflectionEnum($enumName))->getBackingType();
            $line .= $backing === null ? '' : ': ' . $backing;
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            $line .= ' extends ' . $parent->getName();
        }
        // Only what the class names itself: an inherited interface is on its parent's line.
        $own = $class->getInterfaceNames();
        if ($parent !== false) {
            $own = array_diff($own, $parent->getInterfaceNames());
        }
        foreach ($class->getInterfaces() as $interface) {
            $own = array_diff($own, $interface->getInterfaceNames());
        }
        if ($class->isEnum()) {
            $own = array_diff($own, ['UnitEnum', 'BackedEnum']);
        }
        sort($own, SORT_STRING);
        if ($own !== []) {
            $line .= ($class->isInterface() ? ' extends ' : ' implements ') . implode(', ', $own);
        }
        $traits = $class->getTraitNames();
        sort($traits, SORT_STRING);
        if ($traits !== []) {
            $line .= ' uses ' . implode(', ', $traits);
        }

        return $line;
    }

    private function method(string $class, ReflectionMethod $method): string
    {
        $modifiers = ($method->isAbstract() && !$method->getDeclaringClass()->isInterface() ? 'abstract ' : '')
            . ($method->isFinal() ? 'final ' : '')
            . ($method->isStatic() ? 'static ' : '');
        $parameters = array_map(static fn(ReflectionParameter $parameter): string => self::parameter($parameter, $class), $method->getParameters());
        $return = $method->hasReturnType() ? ': ' . self::type($method->getReturnType(), $class) : '';

        return sprintf('%s::%s(%s)%s%s', $class, $method->getName(), implode(', ', $parameters), $return, $modifiers === '' ? '' : ' ' . trim($modifiers));
    }

    private static function parameter(ReflectionParameter $parameter, string $class): string
    {
        $type = self::type($parameter->getType(), $class);
        $text = ($type === '' ? '' : $type . ' ')
            . ($parameter->isPassedByReference() ? '&' : '')
            . ($parameter->isVariadic() ? '...' : '')
            . '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = ' . self::defaultValue($parameter);
        }

        return $text;
    }

    private static function defaultValue(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueConstant()) {
            // Written the same whether PHP names the constant through `self` or its class.
            $constant = (string) $parameter->getDefaultValueConstantName();
            $class = $parameter->getDeclaringClass();

            return $class !== null && str_starts_with($constant, 'self::')
                ? $class->getName() . substr($constant, strlen('self'))
                : $constant;
        }
        $value = $parameter->getDefaultValue();
        if ($value instanceof \UnitEnum) {
            return $value::class . '::' . $value->name;
        }
        if (is_object($value)) {
            return 'new ' . $value::class;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return $value === [] ? '[]' : str_replace(["\n", '  '], ['', ''], var_export($value, true));
        }

        return var_export($value, true);
    }

    /**
     * @param string $self The class `self` names: PHP 8.1 writes `self`, later versions the class.
     */
    private static function type(?ReflectionType $type, string $self): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName() === 'self' ? $self : $type->getName();

            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' . $name : $name;
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $members = array_map(
                static fn(ReflectionType $member): string => $member instanceof ReflectionIntersectionType ? '(' . self::type($member, $self) . ')' : self::type($member, $self),
                $type->getTypes(),
            );
            sort($members, SORT_STRING);

            return implode($type instanceof ReflectionUnionType ? '|' : '&', $members);
        }

        return (string) $type;
    }

    /**
     * `cases()`, `from()` and `tryFrom()`, which every enum has.
     *
     * @param ReflectionClass<object> $class
     */
    private static function isEnumBuiltIn(ReflectionClass $class, ReflectionMethod $method): bool
    {
        return $class->isEnum() && $method->isInternal();
    }
}
