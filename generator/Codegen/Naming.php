<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * How spec names become PHP names.
 */
final class Naming
{
    /** Words PHP refuses as a class name, compared lower case. */
    private const RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false',
        'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match',
        'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'resource', 'return', 'self', 'static', 'string',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * `orders.create` → `OrdersCreate`, `print_files` → `PrintFiles`,
     * `designId` → `DesignId`.
     */
    public static function pascal(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $pascal = implode('', array_map(ucfirst(...), $words === false ? [] : $words));
        if ($pascal === '' || ctype_digit($pascal[0])) {
            $pascal = 'N' . $pascal;
        }

        return $pascal;
    }

    /**
     * The runtime classes every generated model imports, compared lower
     * case: a component of the same name would clash with its import.
     */
    private const RUNTIME_CLASS_NAMES = ['decode', 'encode', 'model', 'omitted', 'unexpectedresponse'];

    /**
     * A class name, kept clear of PHP's reserved words and of the runtime
     * classes models import.
     */
    public static function className(string $name): string
    {
        $lower = strtolower($name);

        return in_array($lower, self::RESERVED_CLASS_NAMES, true) || in_array($lower, self::RUNTIME_CLASS_NAMES, true)
            ? $name . 'Model'
            : $name;
    }

    /**
     * Whether a class name is one of the runtime classes models import.
     */
    public static function isRuntimeClass(string $name): bool
    {
        return in_array(strtolower($name), self::RUNTIME_CLASS_NAMES, true);
    }

    /**
     * A JSON field name as a PHP property name: kept when it is already a
     * valid identifier, camel-cased otherwise.
     */
    public static function property(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
            return $name;
        }

        return lcfirst(self::pascal($name));
    }

    /**
     * A value as a constant name: `order.created` → `ORDER_CREATED`,
     * `designId` → `DESIGN_ID`.
     */
    public static function constant(string $value): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;
        $name = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $snake) ?? $snake, '_'));
        if ($name === '' || ctype_digit($name[0])) {
            $name = 'VALUE_' . $name;
        }
        if (in_array(strtolower($name), ['class'], true)) {
            $name .= '_';
        }

        return rtrim($name, '_') === '' ? 'VALUE' : $name;
    }
}
