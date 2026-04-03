<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for request DTOs with explicit typed properties
 *
 * Properties must have #[Field] attribute to be processed from HTTP requests.
 *
 * Example:
 * ```php
 * #[Field(rules: 'required|string')]
 * public string $name;
 *
 * #[Field(rules: 'nullable|string')]
 * public ?string $description = null;
 * ```
 */
abstract class Request
{
    /** @var array<string, ReflectionClass<object>> */
    private static array $reflectionCache = [];

    /** @var array<string, array<string, true>> */
    private static array $excludedCache = [];

    /**
     * Cache for group properties (static, shared across instances)
     * @var array<string, array<string, array<array{property: ReflectionProperty, mapTo: ?string}>>>
     */
    private static array $groupCache = [];

    /**
     * @param class-string $class
     * @return ReflectionClass<object>
     */
    private static function getReflection(string $class): ReflectionClass
    {
        return self::$reflectionCache[$class] ??= new ReflectionClass($class);
    }

    /**
     * @param class-string $class
     * @return array<string, true>
     */
    private static function getExcluded(string $class): array
    {
        if (!isset(self::$excludedCache[$class])) {
            $excluded = [];
            foreach (self::getReflection($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $attributes = $property->getAttributes(Attributes\Field::class);
                if (!empty($attributes) && $attributes[0]->newInstance()->exclude) {
                    $excluded[$property->getName()] = true;
                }
            }
            self::$excludedCache[$class] = $excluded;
        }

        return self::$excludedCache[$class];
    }

    /**
     * Returns all initialized properties as array (excluding fields with exclude: true)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        $class = static::class;
        $excluded = self::getExcluded($class);

        foreach (self::getReflection($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$property->isInitialized($this)) {
                continue;
            }

            if (isset($excluded[$property->getName()])) {
                continue;
            }

            $value = $property->getValue($this);

            if ($value instanceof self) {
                $value = $value->toArray();
            } elseif (is_array($value)) {
                $value = array_map(
                    static fn($item) => $item instanceof self ? $item->toArray() : $item,
                    $value
                );
            }

            $result[$property->getName()] = $value;
        }

        return $result;
    }

    /**
     * Check if property is initialized (was present in request)
     */
    public function has(string $name): bool
    {
        try {
            $property = self::getReflection(static::class)->getProperty($name);
            return $property->isInitialized($this);
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Get property value or default if not initialized
     */
    public function get(string $name, mixed $default = null): mixed
    {
        try {
            $property = self::getReflection(static::class)->getProperty($name);

            return $property->isInitialized($this)
                ? $property->getValue($this)
                : $default;
        } catch (\ReflectionException) {
            return $default;
        }
    }

    /**
     * Get all properties belonging to a specific group as a flat array
     *
     * Associative arrays are merged by their keys into the result.
     * Scalars and sequential arrays are stored under property name (or mapTo if specified).
     * Empty arrays are skipped.
     *
     * @throws \LogicException When duplicate keys are detected
     * @return array<string, mixed>
     */
    public function group(string $groupName): array
    {
        $class = static::class;

        if (!isset(self::$groupCache[$class][$groupName])) {
            $this->buildGroupCache($class, $groupName);
        }

        $result = [];
        foreach (self::$groupCache[$class][$groupName] as ['property' => $property, 'mapTo' => $mapTo]) {
            if (!$property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            // Skip empty arrays — they carry no criteria info
            // (e.g. SearchProcessor returns [] when all search values are empty)
            if ($value === []) {
                continue;
            }

            if (is_array($value) && !array_is_list($value)) {
                // Associative array — merge key-value pairs into result
                // (e.g. SearchProcessor returns ['name' => ['LIKE' => '%test%']])
                foreach ($value as $key => $v) {
                    if (array_key_exists($key, $result)) {
                        throw new \LogicException(
                            "Duplicate key '$key' in group '$groupName' from property '{$property->getName()}'"
                        );
                    }
                    $result[$key] = $v;
                }
            } else {
                // Scalar or sequential array — store under property/mapTo name
                // (e.g. InFilterProcessor returns ['pending', 'partially_paid'] for IN criteria)
                $key = $mapTo ?? $property->getName();
                if (array_key_exists($key, $result)) {
                    throw new \LogicException(
                        "Duplicate key '$key' in group '$groupName' from property '{$property->getName()}'"
                    );
                }
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param class-string $class
     */
    private function buildGroupCache(string $class, string $groupName): void
    {
        $properties = [];

        foreach (self::getReflection($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(\Solo\RequestHandler\Attributes\Field::class);
            if (empty($attributes)) {
                continue;
            }

            $field = $attributes[0]->newInstance();
            if ($field->group === $groupName) {
                $properties[] = ['property' => $property, 'mapTo' => $field->mapTo];
            }
        }

        self::$groupCache[$class][$groupName] = $properties;
    }

    /**
     * Clear all static caches (reflection, excluded, group)
     *
     * Useful for long-running processes (Swoole, RoadRunner, Laravel Octane)
     * to prevent memory leaks.
     *
     * @param string|null $className Clear cache for specific class only, or all if null
     */
    public static function clearCache(?string $className = null): void
    {
        if ($className === null) {
            self::$reflectionCache = [];
            self::$excludedCache = [];
            self::$groupCache = [];
        } else {
            unset(
                self::$reflectionCache[$className],
                self::$excludedCache[$className],
                self::$groupCache[$className]
            );
        }
    }
}
