<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Exceptions;

use Exception;

/**
 * Thrown when an invalid Request class configuration is detected
 *
 * This exception is thrown during metadata building to catch configuration
 * errors early, before any requests are processed. The one runtime case is
 * `handlerNotInstantiable()`: whether a handler class needs a registered
 * instance is only known when it is first used.
 */
final class ConfigurationException extends Exception
{
    /**
     * Nullable rule used with non-nullable property type
     *
     * Example: #[Validate('nullable')] public string $name;
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
     * Example: #[Cast('string')] public int $id;
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
     * Example: #[Validate('required')] public string $name = 'default';
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
     * Example: #[PreProcess('nonExistentFunction')] public string $name;
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
     * Invalid items class
     *
     * Example: #[Items('NonExistentClass')] public ?array $items = null;
     */
    public static function invalidItems(
        string $className,
        string $propertyName,
        string $itemsClass,
        string $reason
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has invalid items class '%s': %s.",
            $className,
            $propertyName,
            $itemsClass,
            $reason
        ));
    }

    /**
     * Items used on non-array property type
     *
     * Example: #[Items(OrderItemRequest::class)] public string $items;
     */
    public static function itemsRequiresArrayType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'items' but type is '%s'. " .
            "Properties with 'items' must have type 'array' or '?array'.",
            $className,
            $propertyName,
            $propertyType
        ));
    }

    /**
     * Invalid generator class
     *
     * Example: #[Generator('NonExistentClass')] public string $id;
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

    /**
     * Both #[Cast] and #[Caster] declared on the same property.
     */
    public static function castAndCasterConflict(
        string $className,
        string $propertyName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Cast] and #[Caster]. " .
            "Use one or the other — they are mutually exclusive.",
            $className,
            $propertyName
        ));
    }

    /**
     * Custom caster class is missing or does not implement CasterInterface.
     */
    public static function invalidCaster(
        string $className,
        string $propertyName,
        string $casterClass
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has invalid #[Caster(%s)]: " .
            "class does not exist or does not implement CasterInterface.",
            $className,
            $propertyName,
            $casterClass
        ));
    }

    /**
     * #[Cast] received a string that is neither a recognised built-in type nor a class.
     */
    public static function unknownCastType(
        string $className,
        string $propertyName,
        string $castType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[Cast('%s')] which is neither a built-in type " .
            "(int, float, bool, string, array, json) nor a known class.",
            $className,
            $propertyName,
            $castType
        ));
    }

    /**
     * #[Cast] received a class name. Built-in casts only — for custom logic use #[Caster].
     */
    public static function castExpectsBuiltInType(
        string $className,
        string $propertyName,
        string $castType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[Cast('%s')] but that is a class name. " .
            "#[Cast] accepts built-in types only (int, float, bool, string, array, json). " .
            "For custom caster classes use #[Caster(%s::class)].",
            $className,
            $propertyName,
            $castType,
            $castType
        ));
    }

    /**
     * #[Cast('datetime…')] — removed in 4.0: dates are cast by the property type.
     */
    public static function castDateTimeRemoved(
        string $className,
        string $propertyName,
        string $castType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[Cast('%s')]: datetime casts were removed in 4.0. " .
            "Type the property DateTimeImmutable / DateTime / DateTimeInterface — the class " .
            "comes from the type — and add #[DateFormat('…')] for a fixed format.",
            $className,
            $propertyName,
            $castType
        ));
    }

    /**
     * A class-typed property that nothing can produce from request input.
     *
     * Example: public Money $price; — no #[Caster], processor or #[FromContext].
     */
    public static function unproducibleType(
        string $className,
        string $propertyName,
        string $type
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has type '%s', which cannot be produced from request input. " .
            "Add #[Caster(...)], a pre/post-processor or a #[Generator], read it #[FromContext(...)], " .
            "or type it as a Request subclass, a BackedEnum or a DateTime class.",
            $className,
            $propertyName,
            $type
        ));
    }

    /**
     * #[DateFormat] on a property whose type cannot hold a date.
     */
    public static function dateFormatRequiresDateType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[DateFormat] but type '%s'. " .
            "Use DateTimeImmutable, DateTime, DateTimeInterface (or an untyped / mixed property).",
            $className,
            $propertyName,
            $propertyType
        ));
    }

    /**
     * #[DateFormat] together with an attribute that produces the value another way.
     */
    public static function dateFormatConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[DateFormat] and #[%s]. They are mutually exclusive.",
            $className,
            $propertyName,
            $conflictingAttribute
        ));
    }

    /**
     * More than one source attribute (#[FromRoute], #[FromContext], #[FromBody])
     * on the same property.
     *
     * @param list<string> $attributeNames All declared source-attribute names, in declaration order.
     */
    public static function multipleSources(
        string $className,
        string $propertyName,
        array $attributeNames
    ): self {
        $formatted = implode(', ', array_map(static fn(string $n) => "#[{$n}]", $attributeNames));
        return new self(sprintf(
            "Property %s::\$%s has multiple source attributes (%s). " .
            "A property has exactly one source.",
            $className,
            $propertyName,
            $formatted
        ));
    }

    /**
     * Processor name resolves both as a global function and as a static method
     * on the Request class — ambiguous dispatch.
     */
    public static function ambiguousProcessor(
        string $className,
        string $propertyName,
        string $processorType,
        string $processorName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has %s '%s' that is BOTH a global function and a static method on %s. " .
            "This is ambiguous — rename the method, or pass an explicit class-string " .
            "(e.g. MyProcessor::class) to disambiguate.",
            $className,
            $propertyName,
            $processorType,
            $processorName,
            $className
        ));
    }

    /**
     * #[Items] / nested-DTO / #[Generator] class is abstract and cannot be instantiated.
     */
    public static function abstractClass(
        string $className,
        string $propertyName,
        string $attributeName,
        string $targetClass
    ): self {
        return new self(sprintf(
            "Property %s::\$%s uses #[%s(%s)] but the class is abstract and cannot be instantiated. " .
            "Pass a concrete subclass instead.",
            $className,
            $propertyName,
            $attributeName,
            $targetClass
        ));
    }

    /**
     * #[Generator] used together with another attribute that has no effect for generated values.
     */
    public static function generatorConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Generator] and #[%s]. " .
            "Generated values bypass the input-read and validation pipeline, so #[%s] would never run.",
            $className,
            $propertyName,
            $conflictingAttribute,
            $conflictingAttribute
        ));
    }

    /**
     * #[Items] used together with #[Cast] or #[Caster] (casting is skipped for items properties).
     */
    public static function itemsCastConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Items] and #[%s]. " .
            "Items properties bypass automatic casting — each element is processed through its own DTO instead.",
            $className,
            $propertyName,
            $conflictingAttribute
        ));
    }

    /**
     * #[Ignore] used together with another RequestHandler attribute.
     *
     * Ignored properties skip the input pipeline entirely, so any companion
     * attribute (validation, casting, source, etc.) would never run.
     */
    public static function ignoreConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Ignore] and #[%s]. " .
            "Ignored properties are excluded from the input pipeline, so #[%s] would never run. " .
            "Remove #[Ignore] or remove the other attribute.",
            $className,
            $propertyName,
            $conflictingAttribute,
            $conflictingAttribute
        ));
    }

    /**
     * Processor handler refers to a non-static method on the Request class — cannot be called statically.
     */
    public static function processorMethodNotStatic(
        string $className,
        string $propertyName,
        string $processorType,
        string $methodName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has %s '%s' that exists on the Request class as a NON-static method. " .
            "Mark it static, or use a class implementing ProcessorInterface/CasterInterface.",
            $className,
            $propertyName,
            $processorType,
            $methodName
        ));
    }

    /**
     * Processor handler refers to a static method that is not public — the handler cannot call it.
     */
    public static function processorMethodNotPublic(
        string $className,
        string $propertyName,
        string $processorType,
        string $methodName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has %s '%s' that exists on the Request class as a NON-public method. " .
            "Make it public static.",
            $className,
            $propertyName,
            $processorType,
            $methodName
        ));
    }

    /**
     * Function / static-method processor cannot be called with the value as its only argument.
     *
     * Example: #[PreProcess('explode')] — explode() needs two arguments.
     */
    public static function invalidProcessorSignature(
        string $className,
        string $propertyName,
        string $processorType,
        string $processorName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has %s '%s' that cannot be called with a single argument (the value). " .
            "Wrap it in a static method or a ProcessorInterface class.",
            $className,
            $propertyName,
            $processorType,
            $processorName
        ));
    }

    /**
     * Processor / caster / generator class cannot be instantiated without arguments
     * and no instance was registered for it.
     */
    public static function handlerNotInstantiable(string $handlerClass): self
    {
        return new self(sprintf(
            "Cannot instantiate %s automatically: it is abstract, has a non-public constructor " .
            "or requires constructor arguments. Register an instance with " .
            "RequestHandler::register(%s::class, \$instance).",
            $handlerClass,
            $handlerClass
        ));
    }
}
