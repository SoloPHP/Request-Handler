<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;
use ReflectionProperty;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\Request;

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
        return $this->cache[$className] ??= $this->build($className);
    }

    /**
     * @param class-string $className
     */
    private function build(string $className): RequestMetadata
    {
        $reflection = new ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $fieldAttributes = $property->getAttributes(Field::class);
            $field = !empty($fieldAttributes) ? $fieldAttributes[0]->newInstance() : new Field();

            $metadata = $this->buildPropertyMetadata($property, $field);
            $properties[$metadata->name] = $metadata;
        }

        return new RequestMetadata($className, $properties, $reflection);
    }

    private function buildPropertyMetadata(ReflectionProperty $property, Field $field): PropertyMetadata
    {
        $name = $property->getName();
        $className = $property->getDeclaringClass()->getName();

        $hasDefault = $property->hasDefaultValue();
        $defaultValue = $hasDefault ? $property->getDefaultValue() : null;

        [$phpType, $isNullable] = $this->resolvePropertyType($property->getType());

        $isRequired = $this->hasRule($field->rules, 'required');

        if ($this->hasRule($field->rules, 'nullable') && !$isNullable && $phpType !== null) {
            throw ConfigurationException::nullableRuleWithNonNullableType($className, $name, $phpType);
        }

        if ($isRequired && $hasDefault) {
            throw ConfigurationException::requiredWithDefault($className, $name);
        }

        if ($field->cast !== null && $phpType !== null && !$this->isCastCompatible($field->cast, $phpType)) {
            throw ConfigurationException::castTypeMismatch($className, $name, $field->cast, $phpType);
        }

        $preProcessorKind = $this->classifyProcessor($field->preProcess, $className, $name, 'preProcess');
        $postProcessorKind = $this->classifyProcessor($field->postProcess, $className, $name, 'postProcess');

        if ($field->generator !== null) {
            $this->validateGenerator($field->generator, $className, $name);
        }

        if ($field->items !== null) {
            $this->validateItems($field->items, $className, $name);

            if ($phpType !== null && $phpType !== 'array') {
                throw ConfigurationException::itemsRequiresArrayType($className, $name, $phpType);
            }

            if ($field->generator !== null) {
                throw ConfigurationException::itemsWithGenerator($className, $name);
            }
        }

        $inputName = $field->mapFrom ?? $name;
        [$effectiveCastType, $customCasterClass] = $this->resolveCast($field->cast, $phpType);

        return new PropertyMetadata(
            name: $name,
            inputName: $inputName,
            inputPath: explode('.', $inputName),
            type: $phpType,
            isNullable: $isNullable,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            validationRules: $field->rules,
            castType: $field->cast,
            effectiveCastType: $effectiveCastType,
            customCasterClass: $customCasterClass,
            preProcessor: $field->preProcess,
            preProcessorKind: $preProcessorKind,
            postProcessor: $field->postProcess,
            postProcessorKind: $postProcessorKind,
            postProcessConfig: $field->postProcessConfig,
            group: $field->group,
            isRequired: $isRequired,
            reflection: $property,
            generator: $field->generator,
            generatorOptions: $field->generatorOptions,
            exclude: $field->exclude,
            items: $field->items,
        );
    }

    /**
     * @param \ReflectionType|null $type
     * @return array{0: ?string, 1: bool}
     */
    private function resolvePropertyType(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionUnionType) {
            $names = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), static fn($t) => $t instanceof \ReflectionNamedType)
            );
            return [implode('|', $names), $type->allowsNull()];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName(), $type->allowsNull()];
        }
        return [null, true];
    }

    private function hasRule(?string $rules, string $ruleName): bool
    {
        return $rules !== null && (bool) preg_match('/\b' . $ruleName . '\b/', $rules);
    }

    /**
     * Validate processor and return its dispatch kind. Throws if invalid.
     */
    private function classifyProcessor(
        ?string $processor,
        string $className,
        string $propertyName,
        string $type
    ): ?ProcessorKind {
        if ($processor === null) {
            return null;
        }

        if (function_exists($processor)) {
            return ProcessorKind::Func;
        }

        if (class_exists($processor)) {
            $interfaces = class_implements($processor) ?: [];
            if (isset($interfaces[ProcessorInterface::class])) {
                return ProcessorKind::ProcessorInterface;
            }
            if (isset($interfaces[CasterInterface::class])) {
                return ProcessorKind::CasterInterface;
            }
            throw ConfigurationException::invalidProcessor($className, $propertyName, $type, $processor);
        }

        if (method_exists($className, $processor)) {
            return ProcessorKind::StaticMethod;
        }

        throw ConfigurationException::invalidProcessor($className, $propertyName, $type, $processor);
    }

    /**
     * Resolve cast configuration into a built-in type to apply or a custom CasterInterface class.
     *
     * Returns [effectiveCastType, customCasterClass]. Either may be null:
     * - both null  → no cast (value passes through)
     * - first set  → use BuiltInCaster with that type string
     * - second set → use the named CasterInterface class
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCast(?string $castType, ?string $propertyType): array
    {
        if ($castType !== null) {
            if (BuiltInCaster::isBuiltIn($castType)) {
                return [$castType, null];
            }
            if (class_exists($castType)) {
                $interfaces = class_implements($castType) ?: [];
                if (isset($interfaces[CasterInterface::class])) {
                    return [null, $castType];
                }
            }
            // Class without CasterInterface or unknown string → silent passthrough (legacy behaviour).
            return [null, null];
        }

        if ($propertyType !== null && BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null];
        }

        return [null, null];
    }

    /**
     * Check if cast type is compatible with property type
     * Supports union types (int|float) and nullable types (?string)
     */
    private function isCastCompatible(string $castType, string $propertyType): bool
    {
        if (str_starts_with($castType, 'datetime')) {
            $isImmutable = str_contains($castType, 'immutable');
            $allowedTypes = $isImmutable
                ? ['DateTimeImmutable', 'DateTimeInterface']
                : ['DateTime', 'DateTimeImmutable', 'DateTimeInterface'];

            return $this->isTypeInUnion($propertyType, $allowedTypes);
        }

        if (class_exists($castType)) {
            return true;
        }

        $expectedTypes = match ($castType) {
            'int', 'integer' => ['int'],
            'float', 'double' => ['float', 'int'],
            'bool', 'boolean' => ['bool'],
            'string' => ['string'],
            'array' => ['array'],
            default => null,
        };

        if ($expectedTypes === null) {
            return true;
        }

        return $this->isTypeInUnion($propertyType, $expectedTypes);
    }

    /**
     * @param array<string> $allowedTypes
     */
    private function isTypeInUnion(string $propertyType, array $allowedTypes): bool
    {
        $propertyType = ltrim($propertyType, '?');
        $propertyTypes = array_filter(
            explode('|', $propertyType),
            static fn($t) => $t !== 'null'
        );

        foreach ($propertyTypes as $type) {
            if (in_array($type, $allowedTypes, true)) {
                return true;
            }
        }

        return false;
    }

    private function validateItems(string $items, string $className, string $propertyName): void
    {
        if (!class_exists($items)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'class does not exist');
        }

        if (!is_subclass_of($items, Request::class)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'must extend Request');
        }
    }

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

        $interfaces = class_implements($generator) ?: [];
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
