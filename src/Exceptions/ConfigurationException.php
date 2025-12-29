<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Exceptions;

use Exception;

/**
 * Thrown when an invalid Request class configuration is detected
 *
 * This exception is thrown during metadata building to catch configuration
 * errors early, before any requests are processed.
 */
final class ConfigurationException extends Exception
{
    /**
     * Nullable rule used with non-nullable property type
     *
     * Example: #[Field(rules: 'nullable')] public string $name;
     */
    public static function nullableRuleWithNonNullableType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'nullable' in validation rules but type '%s' doesn't allow null. " .
            "Change type to '?%s' or remove 'nullable' from rules.",
            $className,
            $propertyName,
            $propertyType,
            $propertyType
        ));
    }

    /**
     * Cast type incompatible with property type
     *
     * Example: #[Field(cast: 'string')] public int $id;
     */
    public static function castTypeMismatch(
        string $className,
        string $propertyName,
        string $castType,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has cast type '%s' which is incompatible with property type '%s'. " .
            "Ensure cast result matches property type.",
            $className,
            $propertyName,
            $castType,
            $propertyType
        ));
    }

    /**
     * Required field with default value
     *
     * Example: #[Field(rules: 'required')] public string $name = 'default';
     */
    public static function requiredWithDefault(
        string $className,
        string $propertyName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'required' in rules but also has a default value. " .
            "Required fields should not have defaults - remove 'required' or remove default value.",
            $className,
            $propertyName
        ));
    }

    /**
     * Invalid processor (preProcess or postProcess)
     *
     * Example: #[Field(preProcess: 'nonExistentFunction')] public string $name;
     */
    public static function invalidProcessor(
        string $className,
        string $propertyName,
        string $processorType,
        string $processorName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has invalid %s '%s'. " .
            "Processor must be: a global function, a class implementing ProcessorInterface/CasterInterface, " .
            "or a static method on the Request class.",
            $className,
            $propertyName,
            $processorType,
            $processorName
        ));
    }

    /**
     * Invalid generator class
     *
     * Example: #[Field(generator: 'NonExistentClass')] public string $id;
     */
    public static function invalidGenerator(
        string $className,
        string $propertyName,
        string $generatorClass,
        string $reason
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has invalid generator '%s': %s.",
            $className,
            $propertyName,
            $generatorClass,
            $reason
        ));
    }
}
