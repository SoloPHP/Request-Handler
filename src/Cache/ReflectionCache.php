<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionProperty;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Attributes\DateFormat;
use Solo\RequestHandler\Attributes\EmptyToNull;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\NoTrim;
use Solo\RequestHandler\Attributes\PostProcess;
use Solo\RequestHandler\Attributes\PreProcess;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\FieldSource;
use Solo\RequestHandler\Pipeline\ParameterTypeGuard;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\RuleParser;

/**
 * Caches reflection metadata for request classes.
 *
 * All public non-static properties are managed unless marked `#[Ignore]`.
 * Per-property behaviour is composed from individual attributes. Source
 * attributes (`#[FromRoute]` and `#[FromContext]`) are optional — without
 * one the property uses the default source dictated by the `handle*`
 * method invoked.
 *
 * Metadata is immutable per class, so one process-wide registry —
 * `shared()` — serves both `RequestHandler` and the `Request` accessors.
 *
 * @internal
 */
final class ReflectionCache
{
    private static ?self $shared = null;

    /**
     * `#[Cast]` type → acceptable PHP types for the target property. An int
     * result widens losslessly into a float property, a float result does not
     * narrow into an int one; `mixed` accepts every cast.
     */
    private const array CAST_COMPATIBILITY = [
        'int'    => ['int', 'float'],
        'float'  => ['float'],
        'bool'   => ['bool'],
        'string' => ['string'],
        'array'  => ['array', 'iterable'],
        'json'   => ['string'],
    ];

    /**
     * Built-in types a value read from request input can have: a property
     * typed with one — or with a union containing one — needs no producer.
     */
    private const array INPUT_TYPES = ['int', 'float', 'string', 'bool', 'true', 'false', 'array', 'iterable', 'mixed'];

    /**
     * Scalar union members a value of another type is converted to, in PHP's
     * own coercion order.
     */
    private const array UNION_CASTS = ['int', 'float', 'string', 'bool'];

    /**
     * Union member → the error when a value fits no member, widest member
     * first: a string accepts any scalar, a float any number.
     */
    private const array UNION_FAILURES = [
        'string'   => 'string',
        'float'    => 'numeric',
        'int'      => 'integer',
        'bool'     => 'boolean',
        'true'     => 'boolean',
        'false'    => 'boolean',
        'array'    => 'array',
        'iterable' => 'array',
    ];

    /**
     * Date type (lower-case) → the class a date property is built as.
     * `DateTimeInterface` gets the immutable implementation.
     */
    private const array DATETIME_TYPES = [
        'datetimeimmutable' => DateTimeImmutable::class,
        'datetime'          => DateTime::class,
        'datetimeinterface' => DateTimeImmutable::class,
    ];

    /** @var array<class-string, RequestMetadata> */
    private array $cache = [];

    /**
     * Classes whose metadata is being built — guards the eager `#[Items]`
     * validation against self-referencing trees.
     *
     * @var array<string, true>
     */
    private array $building = [];

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * @param class-string $className
     */
    public function get(string $className): RequestMetadata
    {
        return $this->cache[$className] ??= $this->build($className);
    }

    /**
     * Forget built metadata — for one class, or all when null.
     */
    public function clear(?string $className = null): void
    {
        if ($className === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$className]);
    }

    /**
     * @param class-string $className
     */
    private function build(string $className): RequestMetadata
    {
        $this->building[$className] = true;

        try {
            $reflection = new ReflectionClass($className);
            $emptyToNull = $this->classHasAttribute($reflection, EmptyToNull::class);
            $noTrim = $this->classHasAttribute($reflection, NoTrim::class);
            $properties = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                if (!empty($property->getAttributes(Ignore::class))) {
                    $this->assertIgnoreHasNoCompanions($property, $className);
                    continue;
                }

                $metadata = $this->buildPropertyMetadata($property, $className, $emptyToNull, $noTrim);
                $properties[$metadata->name] = $metadata;
            }

            $generated = array_keys(array_filter(
                $properties,
                static fn(PropertyMetadata $property): bool => $property->generator !== null
            ));
            // Children are built only from client input: a #[FromContext]
            // list holds the application's DTOs as they are.
            $transformed = array_keys(array_filter(
                $properties,
                static fn(PropertyMetadata $property): bool => $property->postProcessorKind !== null
                    || $property->customCasterClass !== null
                    || (
                        ($property->items !== null || $property->nested !== null)
                        && $property->source !== FieldSource::Context
                    )
            ));

            $groups = [];
            foreach ($properties as $name => $property) {
                if ($property->group !== null) {
                    $groups[$property->group][] = $name;
                }
            }

            return new RequestMetadata($className, $properties, $reflection, $generated, $transformed, $groups);
        } finally {
            unset($this->building[$className]);
        }
    }

    /**
     * #[Ignore] cannot coexist with any other RequestHandler attribute —
     * an ignored property never enters the pipeline, so a companion attribute
     * would silently never run. Surface that as a build-time failure.
     */
    private function assertIgnoreHasNoCompanions(ReflectionProperty $property, string $className): void
    {
        $companions = [
            Validate::class    => 'Validate',
            Cast::class        => 'Cast',
            Caster::class      => 'Caster',
            DateFormat::class  => 'DateFormat',
            EmptyToNull::class => 'EmptyToNull',
            NoTrim::class      => 'NoTrim',
            PreProcess::class  => 'PreProcess',
            PostProcess::class => 'PostProcess',
            Generator::class   => 'Generator',
            Items::class       => 'Items',
            Group::class       => 'Group',
            Exclude::class     => 'Exclude',
            FromRoute::class   => 'FromRoute',
            FromContext::class => 'FromContext',
            FromBody::class    => 'FromBody',
        ];

        foreach ($companions as $attrClass => $attrName) {
            if (!empty($property->getAttributes($attrClass))) {
                throw ConfigurationException::ignoreConflict($className, $property->getName(), $attrName);
            }
        }
    }

    /**
     * Whether the class or one of its parents carries the attribute — PHP
     * does not inherit attributes, but a request base class should pass its
     * class-level ones on.
     *
     * @param ReflectionClass<object> $class
     * @param class-string            $attributeClass
     */
    private function classHasAttribute(ReflectionClass $class, string $attributeClass): bool
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if ($current->getAttributes($attributeClass) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $requestClass     The class being built — static-method
     *                                       processors are looked up (and later called) on it,
     *                                       which may be a subclass of the declaring class.
     * @param bool         $classEmptyToNull `#[EmptyToNull]` on the class or a parent.
     * @param bool         $classNoTrim      `#[NoTrim]` on the class or a parent.
     */
    private function buildPropertyMetadata(
        ReflectionProperty $property,
        string $requestClass,
        bool $classEmptyToNull,
        bool $classNoTrim,
    ): PropertyMetadata {
        $name = $property->getName();
        $className = $property->getDeclaringClass()->getName();

        $validate = $this->firstAttribute($property, Validate::class);
        $cast = $this->firstAttribute($property, Cast::class);
        $caster = $this->firstAttribute($property, Caster::class);
        $dateFormat = $this->firstAttribute($property, DateFormat::class);
        $preProcess = $this->firstAttribute($property, PreProcess::class);
        $postProcess = $this->firstAttribute($property, PostProcess::class);
        $generator = $this->firstAttribute($property, Generator::class);
        $items = $this->firstAttribute($property, Items::class);
        $group = $this->firstAttribute($property, Group::class);
        $excludeAttr = $this->firstAttribute($property, Exclude::class);
        $emptyToNull = $this->firstAttribute($property, EmptyToNull::class);
        $noTrim = $this->firstAttribute($property, NoTrim::class);

        [$source, $sourceKey, $pathSegments] = $this->detectSource($property, $name, $className);

        $rules = $validate?->rules;
        $castType = $cast !== null ? strtolower($cast->type) : null;
        $customCasterClass = $caster?->class;

        if ($cast !== null && $caster !== null) {
            throw ConfigurationException::castAndCasterConflict($className, $name);
        }

        // Generator presence forbids every other pipeline attribute, so check
        // that conflict BEFORE running rule-based diagnostics that would
        // otherwise surface a less-relevant error first.
        if ($generator !== null) {
            $this->validateGenerator($generator->class, $className, $name);
            $this->checkGeneratorConflicts($property, $className, $name);
        }

        $hasDefault = $property->hasDefaultValue();
        $defaultValue = $hasDefault ? $property->getDefaultValue() : null;

        [$phpType, $isNullable, $classType] = $this->resolvePropertyType($property->getType());

        $isRequired = $this->hasRule($rules, 'required');
        $validatesWhenAbsent = RuleParser::hasNamePrefix($rules, 'required');

        if ($this->hasRule($rules, 'nullable') && !$isNullable && $phpType !== null) {
            throw ConfigurationException::nullableRuleWithNonNullableType($className, $name, $phpType);
        }

        if ($isRequired && $hasDefault) {
            throw ConfigurationException::requiredWithDefault($className, $name);
        }

        if ($cast !== null && $castType !== null) {
            if (str_starts_with($castType, 'datetime')) {
                throw ConfigurationException::castDateTimeRemoved($className, $name, $cast->type);
            }
            if (!BuiltInCaster::isBuiltIn($castType)) {
                if (class_exists($cast->type)) {
                    throw ConfigurationException::castExpectsBuiltInType($className, $name, $cast->type);
                }
                throw ConfigurationException::unknownCastType($className, $name, $cast->type);
            }
            if ($phpType !== null && !$this->isCastCompatible($castType, $phpType)) {
                throw ConfigurationException::castTypeMismatch($className, $name, $cast->type, $phpType);
            }
        }

        $dateClass = $this->resolveDateClass(
            $dateFormat,
            $cast,
            $caster,
            $items,
            $phpType,
            $classType,
            $className,
            $name
        );

        if ($customCasterClass !== null && !$this->isCasterClass($customCasterClass)) {
            throw ConfigurationException::invalidCaster($className, $name, $customCasterClass);
        }

        [$preProcessorKind, $preProcessorGuard] =
            $this->classifyProcessor($preProcess?->handler, $requestClass, $className, $name, 'preProcess');
        [$postProcessorKind, $postProcessorGuard] =
            $this->classifyProcessor($postProcess?->handler, $requestClass, $className, $name, 'postProcess');

        if ($items !== null) {
            $this->validateChildClass($items->class, $className, $name, 'Items');

            if ($phpType !== null && $phpType !== 'array') {
                throw ConfigurationException::itemsRequiresArrayType($className, $name, $phpType);
            }

            if ($cast !== null) {
                throw ConfigurationException::itemsCastConflict($className, $name, 'Cast');
            }
            if ($caster !== null) {
                throw ConfigurationException::itemsCastConflict($className, $name, 'Caster');
            }
        }

        [$effectiveCastType, $resolvedCasterClass, $enumClass] = $this->resolveCast(
            $castType,
            $customCasterClass,
            $phpType,
            $classType
        );

        // A property typed with a Request subclass is a nested DTO — unless a
        // caster or generator produces the object itself, or the application
        // passes it through context.
        $nested = null;
        if (
            $classType !== null
            && $caster === null
            && $generator === null
            && $source !== FieldSource::Context
            && is_subclass_of($classType, Request::class)
        ) {
            $this->validateChildClass($classType, $className, $name, 'nested');
            $nested = $classType;
        }

        // Fail fast on a type nothing can produce from request input — a
        // class, `object`, an intersection, a union of classes: otherwise the
        // first request carrying the field ends in a TypeError.
        $produced = $nested !== null
            || $dateClass !== null
            || $enumClass !== null
            || $effectiveCastType !== null
            || $resolvedCasterClass !== null
            || $preProcessorKind !== null
            || $postProcessorKind !== null
            || $generator !== null
            || $source === FieldSource::Context;

        $type = $property->getType();
        $members = $this->typeMembers($type);
        $holdsInput = $members === null || array_intersect($members, self::INPUT_TYPES) !== [];

        if (!$produced && !$holdsInput) {
            throw ConfigurationException::unproducibleType($className, $name, (string) $phpType);
        }

        // A union, or a standalone `true` / `false`, is cast member by member.
        [$typeGuard, $unionCasts, $unionFailure] = [null, [], null];
        if (
            $type !== null
            && ($type instanceof \ReflectionUnionType || $members === ['true'] || $members === ['false'])
            && $holdsInput
            && $effectiveCastType === null
            && $enumClass === null
            && $dateClass === null
            && $resolvedCasterClass === null
        ) {
            [$typeGuard, $unionCasts, $unionFailure] = $this->unionCast($type, (array) $members);
        }

        return new PropertyMetadata(
            name: $name,
            source: $source,
            sourceKey: $sourceKey,
            type: $phpType,
            isNullable: $isNullable,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            validationRules: $rules,
            effectiveCastType: $effectiveCastType,
            customCasterClass: $resolvedCasterClass,
            preProcessor: $preProcess?->handler,
            preProcessorKind: $preProcessorKind,
            postProcessor: $postProcess?->handler,
            postProcessorKind: $postProcessorKind,
            postProcessConfig: $postProcess !== null ? $postProcess->config : [],
            group: $group?->name,
            isRequired: $isRequired,
            validatesWhenAbsent: $validatesWhenAbsent,
            reflection: $property,
            generator: $generator?->class,
            generatorOptions: $generator !== null ? $generator->options : [],
            exclude: $excludeAttr !== null,
            items: $items?->class,
            pathSegments: $pathSegments,
            preProcessorGuard: $preProcessorGuard,
            postProcessorGuard: $postProcessorGuard,
            enumClass: $enumClass,
            castFailure: $unionFailure
                ?? $this->castFailure($effectiveCastType, $dateClass, $dateFormat?->format, $enumClass),
            groupMapTo: $group?->mapTo,
            dateClass: $dateClass,
            dateFormat: $dateFormat?->format,
            nested: $nested,
            // What the application supplies — generated and #[FromContext]
            // values — is kept as it is: never auto-trimmed, and left out by
            // a class-level #[EmptyToNull].
            emptyToNull: $generator === null
                && ($emptyToNull !== null || ($classEmptyToNull && $source !== FieldSource::Context)),
            noTrim: $generator === null
                && ($noTrim !== null || $classNoTrim || $source === FieldSource::Context),
            typeGuard: $typeGuard,
            unionCasts: $unionCasts,
            trustedGuard: $source === FieldSource::Context || $generator !== null
                ? ParameterTypeGuard::fromType($property->getType())
                : null,
        );
    }

    /**
     * The lower-cased names of a type's named members — one for a named type,
     * the named ones of a union, none for an intersection. Null when untyped.
     * Whether a value read from request input can have the type is whether
     * one of them is in INPUT_TYPES.
     *
     * @return list<string>|null
     */
    private function typeMembers(?\ReflectionType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        $members = [];
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            if ($member instanceof \ReflectionNamedType) {
                $members[] = strtolower($member->getName());
            }
        }

        return $members;
    }

    /**
     * A union no cast applies to (`int|float`, `int|string`) — or a standalone
     * `true` / `false`: the guard that keeps a value of a member type, the
     * scalar members another value is converted to, and the error when none
     * fits. A literal `true` / `false` member is reached through the bool
     * cast; the guard then keeps only the literal's own value.
     *
     * @param list<string> $members From typeMembers().
     * @return array{0: ?ParameterTypeGuard, 1: list<string>, 2: array{rule: string}|null}
     */
    private function unionCast(\ReflectionType $type, array $members): array
    {
        $failure = null;
        foreach (self::UNION_FAILURES as $member => $rule) {
            if (in_array($member, $members, true)) {
                $failure = ['rule' => $rule];
                break;
            }
        }

        $casts = array_values(array_intersect(self::UNION_CASTS, $members));
        if (!in_array('bool', $casts, true) && array_intersect(['true', 'false'], $members) !== []) {
            $casts[] = 'bool';
        }

        return [ParameterTypeGuard::fromType($type), $casts, $failure];
    }

    /**
     * The class a date property is built as: from a DateTime-family type, or
     * DateTimeImmutable for an untyped / `mixed` property carrying
     * `#[DateFormat]`. Null when the property is not a date or another
     * attribute produces the value.
     *
     * @return class-string<DateTime|DateTimeImmutable>|null
     */
    private function resolveDateClass(
        ?DateFormat $dateFormat,
        ?Cast $cast,
        ?Caster $caster,
        ?Items $items,
        ?string $phpType,
        ?string $classType,
        string $className,
        string $name,
    ): ?string {
        if ($dateFormat !== null) {
            foreach (['Cast' => $cast, 'Caster' => $caster, 'Items' => $items] as $attribute => $present) {
                if ($present !== null) {
                    throw ConfigurationException::dateFormatConflict($className, $name, $attribute);
                }
            }
        }

        if ($cast !== null || $caster !== null) {
            return null;
        }

        $dateClass = $classType !== null ? (self::DATETIME_TYPES[strtolower($classType)] ?? null) : null;

        if ($dateFormat !== null && $dateClass === null) {
            if ($phpType !== null && $phpType !== 'mixed') {
                throw ConfigurationException::dateFormatRequiresDateType($className, $name, $phpType);
            }
            $dateClass = DateTimeImmutable::class;
        }

        return $dateClass;
    }

    /**
     * The validation error reported when a built-in cast cannot convert
     * non-empty input — named like the validator rule for that type. Null for
     * an array cast, which accepts any input.
     *
     * @param class-string<BackedEnum>|null $enumClass
     * @return array{rule: string, params?: list<string>}|null
     */
    private function castFailure(
        ?string $effectiveCastType,
        ?string $dateClass,
        ?string $dateFormat,
        ?string $enumClass
    ): ?array {
        if ($enumClass !== null) {
            return [
                'rule' => 'in',
                'params' => array_map(
                    static fn(BackedEnum $case): string => (string) $case->value,
                    $enumClass::cases()
                ),
            ];
        }

        if ($dateClass !== null) {
            return $dateFormat === null ? ['rule' => 'date'] : ['rule' => 'date_format', 'params' => [$dateFormat]];
        }

        return match ($effectiveCastType) {
            'int' => ['rule' => 'integer'],
            'float' => ['rule' => 'numeric'],
            'bool' => ['rule' => 'boolean'],
            'string' => ['rule' => 'string'],
            'json' => ['rule' => 'json'],
            default => null,
        };
    }

    /**
     * Determine the property's source.
     *
     * Returns `[null, $propertyName, null]` when no source attribute is present —
     * the handler will substitute the method-level default at runtime.
     *
     * The third tuple element is non-null only for `#[FromBody]`: an array of
     * dot-path segments used for nested traversal.
     *
     * @return array{0: ?FieldSource, 1: string, 2: ?list<string>}
     */
    private function detectSource(ReflectionProperty $property, string $name, string $className): array
    {
        $fromRoute = $property->getAttributes(FromRoute::class);
        $fromContext = $property->getAttributes(FromContext::class);
        $fromBody = $property->getAttributes(FromBody::class);

        $declared = [];
        if (!empty($fromRoute)) {
            $declared[] = 'FromRoute';
        }
        if (!empty($fromContext)) {
            $declared[] = 'FromContext';
        }
        if (!empty($fromBody)) {
            $declared[] = 'FromBody';
        }

        if (count($declared) > 1) {
            throw ConfigurationException::multipleSources($className, $name, $declared);
        }

        if (!empty($fromRoute)) {
            /** @var FromRoute $attr */
            $attr = $fromRoute[0]->newInstance();
            return [FieldSource::Route, $attr->key ?? $name, null];
        }

        if (!empty($fromContext)) {
            /** @var FromContext $attr */
            $attr = $fromContext[0]->newInstance();
            return [FieldSource::Context, $attr->key, null];
        }

        if (!empty($fromBody)) {
            /** @var FromBody $attr */
            $attr = $fromBody[0]->newInstance();
            $segments = explode('.', $attr->path);
            return [FieldSource::Body, $attr->path, $segments];
        }

        return [null, $name, null];
    }

    private function checkGeneratorConflicts(
        ReflectionProperty $property,
        string $className,
        string $propertyName,
    ): void {
        $conflicts = [
            FromRoute::class   => 'FromRoute',
            FromContext::class => 'FromContext',
            FromBody::class    => 'FromBody',
            Validate::class    => 'Validate',
            Cast::class        => 'Cast',
            Caster::class      => 'Caster',
            DateFormat::class  => 'DateFormat',
            EmptyToNull::class => 'EmptyToNull',
            NoTrim::class      => 'NoTrim',
            PreProcess::class  => 'PreProcess',
            PostProcess::class => 'PostProcess',
            Items::class       => 'Items',
        ];

        foreach ($conflicts as $attrClass => $name) {
            if (!empty($property->getAttributes($attrClass))) {
                throw ConfigurationException::generatorConflict($className, $propertyName, $name);
            }
        }
    }

    /**
     * @template TAttr of object
     * @param class-string<TAttr> $attributeClass
     * @return TAttr|null
     */
    private function firstAttribute(ReflectionProperty $property, string $attributeClass): ?object
    {
        $attributes = $property->getAttributes($attributeClass);
        return !empty($attributes) ? $attributes[0]->newInstance() : null;
    }

    /**
     * Returns [type string, nullable, class name]. The class name is set only
     * for a single named class type — the one shape that can be an enum or a
     * DateTime, so unions, intersections and `mixed` never probe the autoloader.
     *
     * @return array{0: ?string, 1: bool, 2: ?string}
     */
    private function resolvePropertyType(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionUnionType) {
            $names = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), static fn($t) => $t instanceof \ReflectionNamedType)
            );
            return [implode('|', $names), $type->allowsNull(), null];
        }
        if ($type instanceof \ReflectionIntersectionType) {
            $names = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), static fn($t) => $t instanceof \ReflectionNamedType)
            );
            // Intersection types cannot include `null` — they're never nullable.
            return [implode('&', $names), false, null];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName(), $type->allowsNull(), $type->isBuiltin() ? null : $type->getName()];
        }
        return [null, true, null];
    }

    /**
     * Whether `$rules` (pipe-separated) contains a rule whose name equals `$ruleName`.
     *
     * Delegates to the shared {@see RuleParser}, which splits on `|` first to
     * avoid false matches inside value lists (e.g. `in:nullable,active` must NOT
     * match `nullable`).
     */
    private function hasRule(?string $rules, string $ruleName): bool
    {
        return RuleParser::has($rules, $ruleName);
    }

    /**
     * Validate a pre/post-process handler and resolve how to dispatch it.
     *
     * Resolution order: class-string → method-on-Request → global function.
     * If the unqualified name BOTH names a global function AND exists as a
     * static method on the Request class (e.g. `'trim'`), we throw
     * `ambiguousProcessor` rather than silently picking one — the user must
     * disambiguate by renaming or by passing a class-string explicitly.
     *
     * Methods are looked up on `$requestClass` (the class being built), which
     * is also the class they are called on — a subclass may supply the hook
     * for a property declared on its parent. Messages name `$declaringClass`.
     *
     * @return array{0: ?ProcessorKind, 1: ?ParameterTypeGuard}
     */
    private function classifyProcessor(
        ?string $processor,
        string $requestClass,
        string $declaringClass,
        string $propertyName,
        string $type
    ): array {
        if ($processor === null) {
            return [null, null];
        }

        if (class_exists($processor)) {
            $interfaces = class_implements($processor) ?: [];
            if (isset($interfaces[ProcessorInterface::class])) {
                return [ProcessorKind::ProcessorInterface, null];
            }
            if (isset($interfaces[CasterInterface::class])) {
                return [ProcessorKind::CasterInterface, null];
            }
            throw ConfigurationException::invalidProcessor($declaringClass, $propertyName, $type, $processor);
        }

        $isFunc = function_exists($processor);
        $isMethod = method_exists($requestClass, $processor);

        if ($isFunc && $isMethod) {
            throw ConfigurationException::ambiguousProcessor($declaringClass, $propertyName, $type, $processor);
        }

        if ($isMethod) {
            $method = new ReflectionMethod($requestClass, $processor);
            if (!$method->isStatic()) {
                throw ConfigurationException::processorMethodNotStatic(
                    $declaringClass,
                    $propertyName,
                    $type,
                    $processor
                );
            }
            if (!$method->isPublic()) {
                throw ConfigurationException::processorMethodNotPublic(
                    $declaringClass,
                    $propertyName,
                    $type,
                    $processor
                );
            }
            return [ProcessorKind::StaticMethod, $this->callableGuard($method, $declaringClass, $propertyName, $type)];
        }

        if ($isFunc) {
            $function = new ReflectionFunction($processor);
            return [ProcessorKind::Func, $this->callableGuard($function, $declaringClass, $propertyName, $type)];
        }

        throw ConfigurationException::invalidProcessor($declaringClass, $propertyName, $type, $processor);
    }

    /**
     * Function and static-method handlers receive exactly one argument — the
     * value — so they must be callable with one. The first parameter's type
     * becomes a runtime guard: input of another type (a pre-processor sees raw
     * client input) skips the handler instead of raising a TypeError.
     */
    private function callableGuard(
        ReflectionFunctionAbstract $callable,
        string $declaringClass,
        string $propertyName,
        string $type
    ): ?ParameterTypeGuard {
        // User functions ignore surplus arguments; internal ones reject them.
        $takesNoArgument = $callable->getNumberOfParameters() === 0 && $callable->isInternal();

        if ($callable->getNumberOfRequiredParameters() > 1 || $takesNoArgument) {
            throw ConfigurationException::invalidProcessorSignature(
                $declaringClass,
                $propertyName,
                $type,
                $callable->getName()
            );
        }

        return ParameterTypeGuard::fromType(($callable->getParameters()[0] ?? null)?->getType());
    }

    private function isCasterClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $interfaces = class_implements($class) ?: [];
        return isset($interfaces[CasterInterface::class]);
    }

    /**
     * Resolve cast configuration. Returns [effectiveCastType, customCasterClass, enumClass].
     * Dates are resolved separately (`resolveDateClass`).
     *
     * - explicit `#[Caster(...)]`              → [null, casterClass, null]
     * - explicit `#[Cast('int'|…|'json')]`    → [castType, null, null]
     * - no attribute, scalar property type     → [propertyType, null, null]
     * - no attribute, `iterable`               → ['iterable', null, null]
     * - no attribute, BackedEnum property type → [null, null, enumClass]
     * - anything else                          → [null, null, null]
     *
     * @return array{0: ?string, 1: ?string, 2: ?class-string<BackedEnum>}
     */
    private function resolveCast(
        ?string $castType,
        ?string $customCasterClass,
        ?string $propertyType,
        ?string $classType
    ): array {
        if ($customCasterClass !== null) {
            return [null, $customCasterClass, null];
        }

        if ($castType !== null) {
            // castType is validated upstream to be a scalar built-in.
            return [$castType, null, null];
        }

        if ($propertyType !== null && BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null, null];
        }

        if ($propertyType === 'iterable') {
            return ['iterable', null, null];
        }

        if ($classType !== null && is_subclass_of($classType, BackedEnum::class)) {
            return [null, null, $classType];
        }

        return [null, null, null];
    }

    /**
     * `#[Cast]` compatibility check. castType is guaranteed to be a scalar
     * built-in by upstream validation.
     */
    private function isCastCompatible(string $castType, string $propertyType): bool
    {
        return $this->isTypeInUnion($propertyType, self::CAST_COMPATIBILITY[$castType] ?? []);
    }

    /**
     * @param array<string> $allowedTypes Lower-case type names.
     */
    private function isTypeInUnion(string $propertyType, array $allowedTypes): bool
    {
        foreach (explode('|', strtolower($propertyType)) as $type) {
            if ($type === 'mixed' || in_array($type, $allowedTypes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate the Request class of an `#[Items]` list or a nested DTO, and
     * build its metadata now — otherwise its misconfiguration surfaces only
     * when a request first carries it. A class already being built
     * (self-referencing tree) is validated by that build.
     */
    private function validateChildClass(string $child, string $className, string $propertyName, string $kind): void
    {
        if (!class_exists($child)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $child, 'class does not exist');
        }

        if (!is_subclass_of($child, Request::class)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $child, 'must extend Request');
        }

        if (!(new ReflectionClass($child))->isInstantiable()) {
            throw ConfigurationException::abstractClass($className, $propertyName, $kind, $child);
        }

        if (!isset($this->building[$child])) {
            $this->get($child);
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

        if (!(new ReflectionClass($generator))->isInstantiable()) {
            throw ConfigurationException::abstractClass($className, $propertyName, 'Generator', $generator);
        }
    }
}
