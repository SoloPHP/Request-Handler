<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use ReflectionObject;
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
    /**
     * Cached reflection object for this instance
     */
    private ?ReflectionObject $reflection = null;

    /**
     * Cache for group properties (static, shared across instances)
     * @var array<string, array<string, array<ReflectionProperty>>>
     */
    private static array $groupCache = [];

    /**
     * Get cached reflection object for this instance
     */
    private function getReflection(): ReflectionObject
    {
        return $this->reflection ??= new ReflectionObject($this);
    }

    /**
     * Returns all initialized properties as array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->getReflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && $property->isInitialized($this)) {
                $result[$property->getName()] = $property->getValue($this);
            }
        }

        return $result;
    }

    /**
     * Check if property is initialized (was present in request)
     */
    public function has(string $name): bool
    {
        try {
            $property = $this->getReflection()->getProperty($name);
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
            $property = $this->getReflection()->getProperty($name);

            return $property->isInitialized($this)
                ? $property->getValue($this)
                : $default;
        } catch (\ReflectionException) {
            return $default;
        }
    }

    /**
     * Get all properties belonging to a specific group
     *
     * @return array<string, mixed>
     */
    public function group(string $groupName): array
    {
        $class = static::class;

        if (!isset(self::$groupCache[$class][$groupName])) {
            $this->buildGroupCache($class, $groupName);
        }

        $result = [];
        foreach (self::$groupCache[$class][$groupName] as $property) {
            if ($property->isInitialized($this)) {
                $result[$property->getName()] = $property->getValue($this);
            }
        }

        return $result;
    }

    private function buildGroupCache(string $class, string $groupName): void
    {
        $properties = [];

        foreach ($this->getReflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(\Solo\RequestHandler\Attributes\Field::class);
            if (empty($attributes)) {
                continue;
            }

            $field = $attributes[0]->newInstance();
            if ($field->group === $groupName) {
                $properties[] = $property;
            }
        }

        self::$groupCache[$class][$groupName] = $properties;
    }

    /**
     * Clear the static group cache
     *
     * Useful for long-running processes (Swoole, RoadRunner, Laravel Octane)
     * to prevent memory leaks.
     *
     * @param string|null $className Clear cache for specific class only, or all if null
     */
    public static function clearGroupCache(?string $className = null): void
    {
        if ($className === null) {
            self::$groupCache = [];
        } else {
            unset(self::$groupCache[$className]);
        }
    }
}
