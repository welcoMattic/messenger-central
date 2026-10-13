<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Failure;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use TypeError;
use UnitEnum;

use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_push;
use function array_values;
use function class_exists;
use function enum_exists;
use function is_a;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_check_encoding;
use function spl_object_id;
use function sprintf;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Turns the properties of a message into a JSON document that can be edited
 * by hand, and the edited document back into a message.
 *
 * It does not depend on the serializer of the transport, whose payload may
 * not be readable (PHP serialization). Values that JSON cannot hold, like
 * closures or instances of PHP classes other than dates, are left out of the
 * document and kept as they are.
 */
final class MessageJson
{
    private const int MAX_DEPTH = 16;
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const int JSON_FLAGS =
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR;

    /**
     * @return array{json: string, kept: list<string>}|null null when the message cannot be edited
     */
    public static function export(object $message): ?array
    {
        if (!self::isEditable($message::class)) {
            return null;
        }

        $kept = [];
        $json = json_encode(self::exportObject($message, '', $kept, [], 0), self::JSON_FLAGS);

        return false === $json ? null : ['json' => $json, 'kept' => $kept];
    }

    /**
     * Builds a copy of the message with the properties of the JSON document.
     *
     * Properties missing from the document keep their value. The message is
     * returned as is when the document did not change.
     *
     * @throws FailedMessageException when the document does not fit the message
     */
    public static function import(object $message, string $json): object
    {
        if (!self::isEditable($message::class)) {
            throw new FailedMessageException('This message cannot be edited.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new FailedMessageException(sprintf('The message is not valid JSON: %s.', $error->getMessage()));
        }

        if (!is_array($data) || [] !== $data && array_is_list($data)) {
            throw new FailedMessageException('The message must be a JSON object.');
        }

        $kept = [];
        if (self::exportObject($message, '', $kept, [], 0) === $data) {
            return $message;
        }

        return self::importObject($message::class, $message, $data, '');
    }

    /**
     * @param list<string>      $kept
     * @param array<int, true>  $stack objects being exported, to stop on cycles
     *
     * @return array<array-key, mixed>
     */
    private static function exportObject(object $object, string $path, array &$kept, array $stack, int $depth): array
    {
        $stack[spl_object_id($object)] = true;
        $data = [];
        foreach (self::named(self::stored($object::class)) as $name => $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            $exported = self::exportValue(
                $property->getRawValue($object),
                self::path($path, $name),
                $kept,
                $stack,
                $depth + 1,
            );
            if (null === $exported) {
                $kept[] = self::path($path, $name);

                continue;
            }

            $data[$name] = $exported[0];
        }

        return $data;
    }

    /**
     * @param list<string>     $kept
     * @param array<int, true> $stack
     *
     * @return array{mixed}|null the value as JSON holds it, null when it cannot
     */
    private static function exportValue(mixed $value, string $path, array &$kept, array $stack, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        return match (true) {
            null === $value, is_bool($value), is_int($value) => [$value],
            is_float($value) => is_finite($value) ? [$value] : null,
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? [$value] : null,
            is_array($value) => self::exportArray($value, $path, $kept, $stack, $depth),
            $value instanceof BackedEnum => [$value->value],
            $value instanceof UnitEnum => [$value->name],
            $value instanceof DateTimeInterface => [$value->format(self::DATE_FORMAT)],
            is_object($value) && !isset($stack[spl_object_id($value)]) && self::isEditable($value::class) => [
                self::exportObject($value, $path, $kept, $stack, $depth),
            ],
            default => null,
        };
    }

    /**
     * An array is exported whole or not at all: a missing item would be removed.
     *
     * @param array<array-key, mixed> $value
     * @param list<string>            $kept
     * @param array<int, true>        $stack
     *
     * @return array{array<array-key, mixed>}|null
     */
    private static function exportArray(array $value, string $path, array &$kept, array $stack, int $depth): ?array
    {
        $itemsKept = [];
        $data = [];
        foreach ($value as $key => $item) {
            $exported = self::exportValue($item, $path . '[' . $key . ']', $itemsKept, $stack, $depth + 1);
            if (null === $exported) {
                return null;
            }

            $data[$key] = $exported[0];
        }

        array_push($kept, ...$itemsKept);

        return [$data];
    }

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $data
     */
    private static function importObject(string $class, ?object $template, array $data, string $path): object
    {
        $stored = self::stored($class);
        $named = self::named($stored);
        foreach ($data as $name => $_) {
            if (!isset($named[(string) $name])) {
                throw new FailedMessageException(sprintf(
                    '"%s" is not a property of %s.',
                    self::path($path, (string) $name),
                    $class,
                ));
            }
        }

        $object = new ReflectionClass($class)->newInstanceWithoutConstructor();
        foreach ($stored as $property) {
            $value = self::propertyValue($property, $named[$property->name] === $property, $template, $data, $path);
            if (null === $value) {
                continue;
            }

            try {
                $property->setRawValue($object, $value[0]);
            } catch (TypeError $error) { // @mago-expect analysis:avoid-catching-error the type of an edited value is only known here
                throw new FailedMessageException(sprintf(
                    '"%s": %s',
                    self::path($path, $property->name),
                    $error->getMessage(),
                ));
            }
        }

        return $object;
    }

    /**
     * @param bool                    $shown whether the property is in the document
     * @param array<array-key, mixed> $data
     *
     * @return array{mixed}|null null when the property stays uninitialized
     */
    private static function propertyValue(
        ReflectionProperty $property,
        bool $shown,
        ?object $template,
        array $data,
        string $path,
    ): ?array {
        $name = $property->name;
        $original = null !== $template && $property->isInitialized($template)
            ? [$property->getRawValue($template)]
            : null;
        if (!$shown || !array_key_exists($name, $data)) {
            return $original;
        }

        return [self::importValue($data[$name], $original, $property->getType(), self::path($path, $name))];
    }

    /**
     * @param array{mixed}|null $original the value it replaces, when there is one
     */
    private static function importValue(mixed $value, ?array $original, ?ReflectionType $type, string $path): mixed
    {
        if (null !== $original) {
            $template = $original[0];
            $kept = [];
            if (self::exportValue($template, $path, $kept, [], 0) === [$value]) {
                return $template;
            }

            if (null !== $value) {
                if ($template instanceof UnitEnum) {
                    return self::importEnum($template::class, $value, $path);
                }

                if ($template instanceof DateTimeInterface) {
                    return self::importDate($template::class, $value, $path);
                }

                if (is_array($template) && is_array($value)) {
                    return self::importArray($template, $value, $path);
                }

                if (is_object($template)) {
                    if (!self::isEditable($template::class)) {
                        throw new FailedMessageException(sprintf('"%s" cannot be edited.', $path));
                    }

                    if (!is_array($value)) {
                        throw new FailedMessageException(sprintf('"%s" must be a JSON object.', $path));
                    }

                    return self::importObject($template::class, $template, $value, $path);
                }
            }
        }

        return self::importTyped($value, $type, $path);
    }

    /**
     * Items are rebuilt from the item they replace, and new ones from the first item.
     *
     * @param array<array-key, mixed> $template
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function importArray(array $template, array $value, string $path): array
    {
        $first = [] === $template ? null : $template[array_key_first($template)];
        $result = [];
        foreach ($value as $key => $item) {
            $original = match (true) {
                array_key_exists($key, $template) => [$template[$key]],
                self::fits($first, $item) => [$first],
                default => null,
            };
            $result[$key] = self::importValue($item, $original, null, $path . '[' . $key . ']');
        }

        return $result;
    }

    /**
     * Whether a new item looks like the first one, which then tells what to build.
     */
    private static function fits(mixed $template, mixed $item): bool
    {
        return match (true) {
            $template instanceof UnitEnum, $template instanceof DateTimeInterface => is_string($item) || is_int($item),
            is_object($template) => is_array($item),
            default => false,
        };
    }

    /**
     * Builds a value with nothing to start from, as its declared type tells.
     */
    private static function importTyped(mixed $value, ?ReflectionType $type, string $path): mixed
    {
        if (null === $value || !$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $class = $type->getName();
        if (enum_exists($class)) {
            return self::importEnum($class, $value, $path);
        }

        if (is_a($class, DateTimeInterface::class, true)) {
            return self::importDate(
                DateTimeInterface::class === $class ? DateTimeImmutable::class : $class,
                $value,
                $path,
            );
        }

        if (is_array($value) && class_exists($class) && self::isEditable($class)) {
            return self::importObject($class, null, $value, $path);
        }

        return $value;
    }

    /**
     * @param enum-string $class
     */
    private static function importEnum(string $class, mixed $value, string $path): UnitEnum
    {
        // Backed enums are written with their value, the others with the name of the case
        foreach (new ReflectionEnum($class)->getCases() as $case) {
            $written = $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : $case->name;
            if ($written === $value) {
                return $case->getValue();
            }
        }

        throw new FailedMessageException(sprintf(
            '"%s": %s is not a case of %s.',
            $path,
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $class,
        ));
    }

    /**
     * @param class-string<DateTimeInterface> $class
     */
    private static function importDate(string $class, mixed $value, string $path): DateTimeInterface
    {
        try {
            $date = is_string($value) ? new DateTimeImmutable($value) : null;
        } catch (Exception) {
            $date = null;
        }

        if (null === $date) {
            throw new FailedMessageException(sprintf(
                '"%s": %s is not a date.',
                $path,
                json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }

        return match (true) {
            is_a($class, DateTime::class, true) => $class::createFromInterface($date),
            is_a($class, DateTimeImmutable::class, true) => $class::createFromInterface($date),
            default => $date,
        };
    }

    /**
     * Instances of PHP classes (closures, collections...) keep a state that
     * reflection cannot reach.
     */
    private static function isEditable(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isEnum()) {
            return false;
        }

        for ($ancestor = $reflection; false !== $ancestor; $ancestor = $ancestor->getParentClass()) {
            if ($ancestor->isInternal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every property that holds a value: a private property of a parent class
     * is distinct from a property of the same name in a child class.
     *
     * @param class-string $class
     *
     * @return list<ReflectionProperty>
     */
    private static function stored(string $class): array
    {
        $properties = [];
        for (
            $reflection = new ReflectionClass($class);
            false !== $reflection;
            $reflection = $reflection->getParentClass()
        ) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || $property->isVirtual()) {
                    continue;
                }

                $key = $property->isPrivate() ? $property->class . '::' . $property->name : $property->name;
                $properties[$key] ??= $property;
            }
        }

        return array_values($properties);
    }

    /**
     * The properties shown in the document, by name: the ones of the class
     * win over the private ones of its parents.
     *
     * @param list<ReflectionProperty> $stored
     *
     * @return array<string, ReflectionProperty>
     */
    private static function named(array $stored): array
    {
        $named = [];
        foreach ($stored as $property) {
            $named[$property->name] ??= $property;
        }

        return $named;
    }

    private static function path(string $parent, string $name): string
    {
        return '' === $parent ? $name : $parent . '.' . $name;
    }
}
