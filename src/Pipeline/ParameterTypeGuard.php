<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Whether a value fits a declared type under strict_types — the mode
 * `ProcessorRegistry` calls handlers in.
 *
 * Built once per function/static-method handler at metadata-build time and
 * consulted per call: a client-supplied value of the wrong type skips the
 * handler (validation then reports it) instead of surfacing as a TypeError.
 * A property cast member by member (a union) uses it the same way: a value
 * of a member type is kept, any other one is converted first. The declared
 * type is flattened into plain data up front, so a check does no reflection.
 *
 * @internal
 */
final readonly class ParameterTypeGuard
{
    /**
     * @param list<list<string>> $alternatives Union members, each an intersection
     *                                         of type names (built-in names lower-cased).
     */
    private function __construct(
        private bool $allowsNull,
        private array $alternatives,
    ) {
    }

    /**
     * Null when the parameter accepts anything (untyped or `mixed`).
     */
    public static function fromType(?ReflectionType $type): ?self
    {
        if ($type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed')) {
            return null;
        }

        $alternatives = [];
        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            $intersection = [];
            foreach ($member instanceof ReflectionIntersectionType ? $member->getTypes() : [$member] as $named) {
                if ($named instanceof ReflectionNamedType) {
                    $intersection[] = $named->isBuiltin() ? strtolower($named->getName()) : $named->getName();
                }
            }
            $alternatives[] = $intersection;
        }

        return new self($type->allowsNull(), $alternatives);
    }

    public function accepts(mixed $value): bool
    {
        if ($value === null) {
            return $this->allowsNull;
        }

        foreach ($this->alternatives as $intersection) {
            foreach ($intersection as $name) {
                if (!self::matches($name, $value)) {
                    continue 2;
                }
            }
            return true;
        }

        return false;
    }

    private static function matches(string $name, mixed $value): bool
    {
        return match ($name) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object', 'self', 'parent' => is_object($value),
            'callable' => is_callable($value),
            'null' => false,
            default => $value instanceof $name,
        };
    }
}
