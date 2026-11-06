<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Support;

use PHPUnit\Framework\Assert;
use Pixonaut\Sdk\Model\Model;

/**
 * Calls into classes generated under a namespace only known at run time.
 */
final class Calls
{
    /**
     * `<class>::fromArray($data)`.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $class, array $data): Model
    {
        $model = self::call($class, 'fromArray', $data);
        Assert::assertInstanceOf(Model::class, $model);

        return $model;
    }

    /**
     * `<class>::decode($data)`.
     */
    public static function decode(string $class, mixed $data): mixed
    {
        return self::call($class, 'decode', $data);
    }

    /**
     * `new <class>(...$arguments)`.
     *
     * @param array<string, mixed> $arguments Named.
     */
    public static function construct(string $class, array $arguments): Model
    {
        $model = (new \ReflectionClass(self::class($class)))->newInstanceArgs($arguments);
        Assert::assertInstanceOf(Model::class, $model);

        return $model;
    }

    /**
     * `<class>::<method>(...$arguments)`.
     */
    public static function call(string $class, string $method, mixed ...$arguments): mixed
    {
        $callback = [$class, $method];
        if (!is_callable($callback)) {
            Assert::fail($class . '::' . $method . '() was not generated.');
        }

        return $callback(...$arguments);
    }

    /**
     * A class that must have been generated.
     *
     * @return class-string
     */
    public static function class(string $class): string
    {
        if (!class_exists($class)) {
            Assert::fail($class . ' was not generated.');
        }

        return $class;
    }

    /**
     * A public property of a generated model.
     */
    public static function get(object $model, string $property): mixed
    {
        $properties = get_object_vars($model);
        Assert::assertArrayHasKey($property, $properties, get_class($model) . ' has no property $' . $property);

        return $properties[$property];
    }

    /**
     * A class constant of a generated class.
     */
    public static function constant(string $class, string $name): mixed
    {
        return constant($class . '::' . $name);
    }
}
