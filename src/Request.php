<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

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
 *
 * @phpstan-type PropertyEntry array{
 *     property: ReflectionProperty,
 *     exclude: bool,
 *     group: ?string,
 *     mapTo: ?string,
 * }
 */
abstract class Request
{
    /**
     * Per-class property metadata used by toArray/has/get/group.
     *
     * @var array<string, array<string, PropertyEntry>>
     */
    private static array $propertyCache = [];

    /**
     * Per-class group lookup, derived from $propertyCache.
     *
     * @var array<string, array<string, list<PropertyEntry>>>
     */
    private static array $groupCache = [];

    /**
     * @param class-string $class
     * @return array<string, PropertyEntry>
     */
    private static function getProperties(string $class): array
    {
        if (isset(self::$propertyCache[$class])) {
            return self::$propertyCache[$class];
        }

        $cache = [];
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(Attributes\Field::class);
            $field = !empty($attributes) ? $attributes[0]->newInstance() : null;

            $cache[$property->getName()] = [
                'property' => $property,
                'exclude'  => $field !== null && $field->exclude,
                'group'    => $field?->group,
                'mapTo'    => $field?->mapTo,
            ];
        }

        return self::$propertyCache[$class] = $cache;
    }

    /**
     * Returns all initialized properties as array (excluding fields with exclude: true)
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (self::getProperties(static::class) as $name => $meta) {
            if ($meta['exclude'] || !$meta['property']->isInitialized($this)) {
                continue;
            }

            $value = $meta['property']->getValue($this);

            if ($value instanceof self) {
                $value = $value->toArray();
            } elseif (is_array($value)) {
                $value = array_map(
                    static fn($item) => $item instanceof self ? $item->toArray() : $item,
                    $value
                );
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Check if property is initialized (was present in request)
     */
    public function has(string $name): bool
    {
        $properties = self::getProperties(static::class);
        return isset($properties[$name]) && $properties[$name]['property']->isInitialized($this);
    }

    /**
     * Get property value or default if not initialized
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $properties = self::getProperties(static::class);
        if (!isset($properties[$name]) || !$properties[$name]['property']->isInitialized($this)) {
            return $default;
        }
        return $properties[$name]['property']->getValue($this);
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
            $members = [];
            foreach (self::getProperties($class) as $meta) {
                if ($meta['group'] === $groupName) {
                    $members[] = $meta;
                }
            }
            self::$groupCache[$class][$groupName] = $members;
        }

        $result = [];
        foreach (self::$groupCache[$class][$groupName] as $meta) {
            $property = $meta['property'];
            if (!$property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            // Skip empty arrays — they carry no criteria info
            if ($value === []) {
                continue;
            }

            $entries = (is_array($value) && !array_is_list($value))
                ? $value
                : [($meta['mapTo'] ?? $property->getName()) => $value];

            foreach ($entries as $key => $v) {
                if (array_key_exists($key, $result)) {
                    throw new \LogicException(
                        "Duplicate key '$key' in group '$groupName' from property '{$property->getName()}'"
                    );
                }
                $result[$key] = $v;
            }
        }

        return $result;
    }

    /**
     * Clear cached metadata. Useful for long-running runtimes (Swoole, RoadRunner, Octane).
     *
     * @param string|null $className Clear cache for specific class only, or all if null
     */
    public static function clearCache(?string $className = null): void
    {
        if ($className === null) {
            self::$propertyCache = [];
            self::$groupCache = [];
            return;
        }

        unset(self::$propertyCache[$className], self::$groupCache[$className]);
    }
}
