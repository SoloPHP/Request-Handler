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
     * The scalar types a value of none of the members may be converted to,
     * in PHP's own coercion order.
     */
    private const array CASTS = ['int', 'float', 'string', 'bool'];

    /**
     * @param list<list<string>> $alternatives Union members, each an intersection
     *                                         of type names (built-in names lower-cased).
     * @param list<string>       $casts        The scalar members a value of none of the types is
     *                                         converted to without loss, in coercion order; a
     *                                         literal `true` / `false` is reached through bool.
     */
    private function __construct(
        private bool $allowsNull,
        private array $alternatives,
        public array $casts,
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

        $members = self::singleTypes($alternatives);
        $casts = array_values(array_intersect(self::CASTS, $members));
        if (!in_array('bool', $casts, true) && array_intersect(['true', 'false'], $members) !== []) {
            $casts[] = 'bool';
        }

        return new self($type->allowsNull(), $alternatives, $casts);
    }

    /**
     * The type's single-type members — built-ins lower-cased, class names as
     * written; intersections are left out. Names what the type accepts.
     *
     * @return list<string>
     */
    public function members(): array
    {
        return self::singleTypes($this->alternatives);
    }

    /**
     * @param list<list<string>> $alternatives
     * @return list<string>
     */
    private static function singleTypes(array $alternatives): array
    {
        $members = [];
        foreach ($alternatives as $intersection) {
            if (count($intersection) === 1) {
                $members[] = $intersection[0];
            }
        }

        return $members;
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
