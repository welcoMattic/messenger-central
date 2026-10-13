<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tools\Mago;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

use function array_push;
use function array_values;
use function basename;
use function class_exists;
use function dirname;
use function glob;
use function interface_exists;
use function ksort;
use function method_exists;

/**
 * The methods of the Symfony configuration builders that open a nesting
 * level, closed by "end()": the ones returning another builder, like
 * "children()", "arrayNode()" or "validate()".
 *
 * They are read from the installed Config component, so that the builders
 * and node types Symfony adds are known as soon as they exist.
 */
final class ConfigBuilderOpeners
{
    private const string CLOSER = 'end';

    /** @var list<class-string> classes whose API is read, besides the ones of their namespace */
    private const array ENTRY_POINTS = [NodeBuilder::class, DefinitionConfigurator::class];

    /** @var array<string, bool>|null */
    private static ?array $openers = null;

    /**
     * @return array<string, bool> method name => whether it opens a level only when called without
     *                             arguments, like "before()" that returns itself when given a closure
     */
    public static function all(): array
    {
        return self::$openers ??= self::discover();
    }

    public static function opens(string $method, int $arguments): bool
    {
        $openers = self::all();
        if (!isset($openers[$method])) {
            return false;
        }

        return !$openers[$method] || 0 === $arguments;
    }

    /**
     * The fluent methods of the builders, that return the builder itself.
     *
     * @return array<string, true>
     */
    public static function fluent(): array
    {
        $fluent = [];
        foreach (self::builders() as $class) {
            foreach (self::methods($class) as $method) {
                [$builders, $static] = self::returns($method, $class);
                if ($static && [] === $builders) {
                    $fluent[$method->name] = true;
                }
            }
        }

        ksort($fluent);

        return $fluent;
    }

    /**
     * @return array<string, bool>
     */
    private static function discover(): array
    {
        $openers = [];
        foreach (self::builders() as $class) {
            foreach (self::methods($class) as $method) {
                [$builders, $static] = self::returns($method, $class);
                if ([] === $builders) {
                    continue;
                }

                // A method that may also return itself opens a level in one of its forms only
                $openers[$method->name] = ($openers[$method->name] ?? true) && $static;
            }
        }

        ksort($openers);

        return $openers;
    }

    /**
     * The classes of the namespaces of the entry points, which is where Symfony keeps its builders.
     *
     * @return list<ReflectionClass<object>>
     */
    private static function builders(): array
    {
        $classes = [];
        foreach (self::ENTRY_POINTS as $entryPoint) {
            if (!class_exists($entryPoint)) {
                continue;
            }

            $reflection = new ReflectionClass($entryPoint);
            $files = glob(dirname((string) $reflection->getFileName()) . '/*.php');
            foreach (false === $files ? [] : $files as $file) {
                $class = $reflection->getNamespaceName() . '\\' . basename($file, '.php');
                if (class_exists($class) || interface_exists($class)) {
                    $classes[$class] = new ReflectionClass($class);
                }
            }
        }

        return array_values($classes);
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionMethod>
     */
    private static function methods(ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || self::CLOSER === $method->name) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return array{list<string>, bool} the builders the method returns, and whether it may return itself
     */
    private static function returns(ReflectionMethod $method, ReflectionClass $class): array
    {
        $builders = [];
        $static = false;
        foreach (self::namedTypes($method->getReturnType()) as $type) {
            $name = $type->getName();
            if ('static' === $name) {
                $static = true;

                continue;
            }

            // "self" is another instance: "arrayPrototype(): self" returns the prototype
            $name = 'self' === $name ? $class->getName() : $name;
            if (!$type->isBuiltin() && self::isBuilder($name)) {
                $builders[] = $name;
            }
        }

        return [$builders, $static];
    }

    /**
     * @return list<ReflectionNamedType>
     */
    private static function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        $named = [];
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                array_push($named, ...self::namedTypes($inner));
            }
        }

        return $named;
    }

    private static function isBuilder(string $class): bool
    {
        return (class_exists($class) || interface_exists($class)) && method_exists($class, self::CLOSER);
    }
}
