<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;
use ReflectionProperty;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\Exceptions\ConfigurationException;

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
        $properties = [];

        // Get all public non-static properties
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // #[Field] is optional - use it if present, otherwise use defaults
            $fieldAttributes = $property->getAttributes(Field::class);
            $field = !empty($fieldAttributes) ? $fieldAttributes[0]->newInstance() : null;

            $metadata = $this->buildPropertyMetadata($property, $field);
            $properties[$metadata->name] = $metadata;
        }

        return new RequestMetadata($className, $properties);
    }

    private function buildPropertyMetadata(ReflectionProperty $property, ?Field $field): PropertyMetadata
    {
        $name = $property->getName();
        $className = $property->getDeclaringClass()->getName();

        // Get default value from property
        $hasDefault = $property->hasDefaultValue();
        $defaultValue = $hasDefault ? $property->getDefaultValue() : null;

        // Get property type
        $type = $property->getType();

        // Handle union types (PHP 8.0+)
        if ($type instanceof \ReflectionUnionType) {
            $types = array_map(
                fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), fn($t) => $t instanceof \ReflectionNamedType)
            );
            $phpType = implode('|', $types);
            $isNullable = $type->allowsNull();
        } elseif ($type instanceof \ReflectionNamedType) {
            $phpType = $type->getName();
            $isNullable = $type->allowsNull();
        } else {
            $phpType = null;
            $isNullable = true;
        }

        // === Configuration Validation ===
        $isRequired = $this->hasRule($field?->rules, 'required');

        // Check 1: nullable in rules vs non-nullable type
        if ($this->hasRule($field?->rules, 'nullable') && !$isNullable && $phpType !== null) {
            throw ConfigurationException::nullableRuleWithNonNullableType($className, $name, $phpType);
        }

        // Check 2: required with default value
        if ($isRequired && $hasDefault) {
            throw ConfigurationException::requiredWithDefault($className, $name);
        }

        // Check 3: cast type vs property type
        if ($field?->cast !== null && $phpType !== null && !$this->isCastCompatible($field->cast, $phpType)) {
            throw ConfigurationException::castTypeMismatch($className, $name, $field->cast, $phpType);
        }

        // Check 4: processors must be callable
        $this->validateProcessor($field?->preProcess, $className, $name, 'preProcess');
        $this->validateProcessor($field?->postProcess, $className, $name, 'postProcess');

        // Check 5: generator must implement GeneratorInterface
        if ($field?->generator !== null) {
            $this->validateGenerator($field->generator, $className, $name);
        }

        // Check 6: items must be a Request subclass
        if ($field?->items !== null) {
            $this->validateItems($field->items, $className, $name);

            // Check 7: items requires array type
            if ($phpType !== null && $phpType !== 'array') {
                throw ConfigurationException::itemsRequiresArrayType($className, $name, $phpType);
            }

            // Check 8: items + generator is invalid
            if ($field->generator !== null) {
                throw ConfigurationException::itemsWithGenerator($className, $name);
            }
        }

        return new PropertyMetadata(
            name: $name,
            inputName: $field->mapFrom ?? $name,
            type: $phpType,
            isNullable: $isNullable,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            validationRules: $field?->rules,
            castType: $field?->cast,
            preProcessor: $field?->preProcess,
            postProcessor: $field?->postProcess,
            group: $field?->group,
            isRequired: $isRequired,
            generator: $field?->generator,
            generatorOptions: $field->generatorOptions ?? [],
            exclude: $field->exclude ?? false,
            items: $field?->items,
        );
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Check if validation rules contain a specific rule (word boundary match)
     */
    private function hasRule(?string $rules, string $ruleName): bool
    {
        return $rules !== null && (bool) preg_match('/\b' . $ruleName . '\b/', $rules);
    }

    /**
     * Validate processor and throw if invalid
     */
    private function validateProcessor(?string $processor, string $className, string $propertyName, string $type): void
    {
        if ($processor !== null && !$this->isValidProcessor($processor, $className)) {
            throw ConfigurationException::invalidProcessor($className, $propertyName, $type, $processor);
        }
    }

    /**
     * Check if cast type is compatible with property type
     * Supports union types (int|float) and nullable types (?string)
     */
    private function isCastCompatible(string $castType, string $propertyType): bool
    {
        // Handle datetime with formats (datetime:Y-m-d or datetime:immutable:Y-m-d)
        if (str_starts_with($castType, 'datetime')) {
            $isImmutable = str_contains($castType, 'immutable');
            $allowedTypes = $isImmutable
                ? ['DateTimeImmutable', 'DateTimeInterface']
                : ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'];

            return $this->isTypeInUnion($propertyType, $allowedTypes);
        }

        // If it's a custom caster class - we can't validate, skip
        if (class_exists($castType)) {
            return true;
        }

        // Mapping of built-in cast types to compatible property types
        $expectedTypes = match ($castType) {
            'int', 'integer' => ['int'],
            'float', 'double' => ['float', 'int'],  // int can be assigned to float
            'bool', 'boolean' => ['bool'],
            'string' => ['string'],
            'array' => ['array'],
            default => null,
        };

        // Unknown cast type - skip validation
        if ($expectedTypes === null) {
            return true;
        }

        // Check compatibility with union types support
        return $this->isTypeInUnion($propertyType, $expectedTypes);
    }

    /**
     * Check if at least one of the allowed types is present in the property's union type
     * Supports: int, int|float, ?string, int|null, etc.
     *
     * @param array<string> $allowedTypes
     */
    private function isTypeInUnion(string $propertyType, array $allowedTypes): bool
    {
        // Remove nullable prefix (?) if present
        $propertyType = ltrim($propertyType, '?');

        // Split union type into individual types
        $propertyTypes = explode('|', $propertyType);

        // Remove 'null' from types list (nullable is handled separately)
        $propertyTypes = array_filter($propertyTypes, fn($t) => $t !== 'null');

        // Check if there's an intersection between property types and allowed types
        foreach ($propertyTypes as $type) {
            if (in_array($type, $allowedTypes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if processor is valid (callable)
     * Valid processors: global function, class with ProcessorInterface/CasterInterface, static method on Request
     */
    private function isValidProcessor(string $processor, string $className): bool
    {
        // 1. Global function
        if (function_exists($processor)) {
            return true;
        }

        // 2. Class with required interface
        if (class_exists($processor)) {
            $interfaces = class_implements($processor);
            return isset($interfaces[\Solo\RequestHandler\Contracts\ProcessorInterface::class])
                || isset($interfaces[\Solo\RequestHandler\Contracts\CasterInterface::class]);
        }

        // 3. Static method on Request class
        if (method_exists($className, $processor)) {
            return true;
        }

        return false;
    }

    /**
     * Validate items class extends Request
     */
    private function validateItems(string $items, string $className, string $propertyName): void
    {
        if (!class_exists($items)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'class does not exist');
        }

        if (!is_subclass_of($items, Request::class)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'must extend Request');
        }
    }

    /**
     * Validate generator class implements GeneratorInterface
     */
    private function validateGenerator(string $generator, string $className, string $propertyName): void
    {
        if (!class_exists($generator)) {
            throw ConfigurationException::invalidGenerator(
                $className,
                $propertyName,
                $generator,
                'class does not exist'
            );
        }

        $interfaces = class_implements($generator);
        if (!isset($interfaces[GeneratorInterface::class])) {
            throw ConfigurationException::invalidGenerator(
                $className,
                $propertyName,
                $generator,
                'must implement GeneratorInterface'
            );
        }
    }
}
