<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Traits;

use Error;

/**
 * Provides dynamic property access for request DTOs
 *
 * Properties are created only for fields present in the request.
 * Accessing a property that wasn't in the request throws an Error.
 *
 * Example:
 * ```php
 * #[AsRequest]
 * #[Field('name', 'required|string')]
 * #[Field('description', 'nullable|string')]
 * final class ProductRequest
 * {
 *     use DynamicProperties;
 * }
 *
 * // Request: ['name' => 'Product']
 * $data = $handler->handle(ProductRequest::class, $request);
 *
 * $data->name;        // 'Product'
 * $data->description; // Error: Undefined property
 *
 * isset($data->name);        // true
 * isset($data->description); // false
 *
 * $data->toArray();   // ['name' => 'Product']
 * ```
 */
trait DynamicProperties
{
    /** @var array<string, mixed> */
    private array $dynamicPropertiesData = [];

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->dynamicPropertiesData)) {
            throw new Error("Undefined property: " . static::class . "::\${$name}");
        }
        return $this->dynamicPropertiesData[$name];
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->dynamicPropertiesData);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->dynamicPropertiesData[$name] = $value;
    }

    /**
     * Returns all present properties as array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->dynamicPropertiesData;
    }

    /**
     * Check if property exists (was present in request)
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->dynamicPropertiesData);
    }

    /**
     * Get property value or default if not present
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->dynamicPropertiesData[$name] ?? $default;
    }
}
