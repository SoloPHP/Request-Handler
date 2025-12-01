<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;
use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use InvalidArgumentException;

/**
 * Caches reflection metadata for request classes
 */
final class ReflectionCache
{
    /** @var array<class-string, RequestMetadata> */
    private array $cache = [];

    /**
     * @param class-string $className
     */
    public function get(string $className): RequestMetadata
    {
        if (!isset($this->cache[$className])) {
            $this->cache[$className] = $this->build($className);
        }

        return $this->cache[$className];
    }

    /**
     * @param class-string $className
     */
    private function build(string $className): RequestMetadata
    {
        $reflection = new ReflectionClass($className);

        $this->validateClass($reflection);

        $properties = [];
        $fieldAttributes = $reflection->getAttributes(Field::class);

        foreach ($fieldAttributes as $attribute) {
            $field = $attribute->newInstance();
            $metadata = $this->buildPropertyMetadata($field);
            $properties[$metadata->name] = $metadata;
        }

        return new RequestMetadata($className, $properties);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function validateClass(ReflectionClass $reflection): void
    {
        $attributes = $reflection->getAttributes(AsRequest::class);
        if (empty($attributes)) {
            throw new InvalidArgumentException(
                "Class {$reflection->getName()} must have #[AsRequest] attribute"
            );
        }
    }

    private function buildPropertyMetadata(Field $field): PropertyMetadata
    {
        return new PropertyMetadata(
            name: $field->name,
            inputName: $field->mapFrom ?? $field->name,
            type: null,
            isNullable: true,
            hasDefault: $field->hasDefault(),
            defaultValue: $field->default,
            validationRules: $field->rules,
            castType: $field->cast,
            preProcessor: $field->preProcess,
            postProcessor: $field->postProcess,
            group: $field->group,
        );
    }

    public function clear(): void
    {
        $this->cache = [];
    }
}
