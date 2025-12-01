<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Error;

/**
 * Base class for request DTOs with dynamic property access
 *
 * Properties are created only for fields present in the request.
 * Accessing a property that wasn't in the request throws an Error.
 *
 * Example:
 * ```php
 * #[AsRequest]
 * #[Field('name', 'required|string')]
 * #[Field('description', 'nullable|string')]
 * final class ProductRequest extends DynamicRequest
 * {
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
 * $data->group('criteria'); // ['search' => '...', 'deleted' => '...']
 * ```
 */
abstract class DynamicRequest
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<string, string> */
    protected array $groups = [];

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            throw new Error("Undefined property: " . static::class . "::\${$name}");
        }
        return $this->data[$name];
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * Returns all present properties as array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Check if property exists (was present in request)
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    /**
     * Get property value or default if not present
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    /**
     * Get all properties belonging to a specific group
     *
     * @return array<string, mixed>
     */
    public function group(string $name): array
    {
        $result = [];
        foreach ($this->data as $property => $value) {
            if (($this->groups[$property] ?? null) === $name) {
                $result[$property] = $value;
            }
        }
        return $result;
    }
}
