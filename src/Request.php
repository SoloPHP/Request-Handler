<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Grouping\GroupBuilder;
use Solo\RequestHandler\Pipeline\ProvidedFields;

/**
 * Base class for request DTOs with explicit typed properties.
 *
 * All public non-static properties are part of the DTO surface and managed
 * by `RequestHandler`. Use `protected` / `private` visibility — or
 * `#[Ignore]` on a public property — for state that must stay outside the
 * input pipeline and output serialisation.
 *
 * Example:
 * ```php
 * use Solo\RequestHandler\Attributes\Validate;
 *
 * #[Validate('required|string')]
 * public string $name;
 *
 * #[Validate('nullable|string')]
 * public ?string $description = null;
 * ```
 */
abstract class Request
{
    /**
     * Returns all initialized properties as array (skips uninitialized and #[Exclude]-marked).
     *
     * With `$onlyProvided`, only the properties the request supplied
     * (`provided()`), nested DTOs and `#[Items]` included — the change set of
     * a PATCH. A nested DTO the handler did not build — one the application
     * passed in context — is a value supplied as a whole.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $onlyProvided = false): array
    {
        $result = [];
        $metadata = $this->metadata();
        $provided = $onlyProvided ? ProvidedFields::of($this) : [];

        foreach ($metadata->properties as $name => $property) {
            if ($property->exclude || !$property->reflection->isInitialized($this)) {
                continue;
            }
            if ($onlyProvided && !isset($provided[$name])) {
                continue;
            }

            $value = $property->reflection->getValue($this);
            $onlyProvidedChildren = $onlyProvided && $property->buildsChildren;

            if ($value instanceof self) {
                $value = $value->toArray($onlyProvidedChildren && ProvidedFields::knows($value));
            } elseif (is_array($value)) {
                // Build a new array: its entries may reference the original DTO.
                $exported = [];
                foreach ($value as $key => $item) {
                    $exported[$key] = $item instanceof self
                        ? $item->toArray($onlyProvidedChildren && ProvidedFields::knows($item))
                        : $item;
                }
                $value = $exported;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Check if property is initialized (was present in request, populated, or has a default).
     */
    public function has(string $name): bool
    {
        $property = $this->metadata()->properties[$name] ?? null;

        return $property !== null && $property->reflection->isInitialized($this);
    }

    /**
     * Names of the properties the request supplied, in declaration order:
     * every property whose key was present in its source (body, query, route
     * or context) — an explicit `null` or `""` included — and the generated
     * ones. A property left at its default because the key was absent is not
     * listed. Empty for a DTO not built by `RequestHandler` — a clone or an
     * unserialized copy included.
     *
     * @return list<string>
     */
    public function provided(): array
    {
        return array_keys(ProvidedFields::of($this));
    }

    /**
     * Whether the request supplied the property — see `provided()`. Unlike
     * `has()`, a default does not count.
     */
    public function isProvided(string $name): bool
    {
        return ProvidedFields::has($this, $name);
    }

    /**
     * Get property value or default if not initialized.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $property = $this->metadata()->properties[$name] ?? null;

        if ($property === null || !$property->reflection->isInitialized($this)) {
            return $default;
        }

        return $property->reflection->getValue($this);
    }

    /**
     * Get all properties belonging to a specific group as a flat array.
     *
     * - Associative array properties: contents are merged by their own keys.
     * - Scalar properties and sequential arrays: added under property name (or `mapTo`).
     * - Empty arrays AND null scalars are skipped — they carry no group info.
     * - Duplicate 'AND'/'OR' keys fold into integer-keyed list entries so
     *   multiple logical groups coexist (the criteria builder AND-combines them).
     *
     * @throws \LogicException When duplicate non-connector keys are detected.
     * @return array<array-key, mixed>
     */
    public function group(string $groupName): array
    {
        return GroupBuilder::build($this, $this->metadata(), $groupName);
    }

    /**
     * Clear the metadata registry shared by `RequestHandler` and these accessors.
     *
     * Primarily a test helper for isolating cases within a single PHP process —
     * metadata is per-class and immutable, so production code never needs to
     * call this.
     *
     * @param string|null $className Clear cache for specific class only, or all if null
     */
    public static function clearCache(?string $className = null): void
    {
        ReflectionCache::shared()->clear($className);
    }

    private function metadata(): RequestMetadata
    {
        return ReflectionCache::of(static::class);
    }
}
