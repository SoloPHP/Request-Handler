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
     * Type → the validator rule a value that is not of it fails, widest first
     * (a string accepts any scalar, a float any number): a union reports the
     * first of its members.
     */
    private const array TYPE_RULES = [
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
     * Attributes that act on a property's input: none may join #[Generator] —
     * a generated value reads no input — or #[Ignore].
     */
    private const array INPUT_ATTRIBUTES = [
        FromRoute::class,
        FromContext::class,
        FromBody::class,
        Validate::class,
        Cast::class,
        Caster::class,
        DateFormat::class,
        EmptyToNull::class,
        NoTrim::class,
        PreProcess::class,
        PostProcess::class,
        Items::class,
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
     * The shared registry's metadata of a class in one call — the path the
     * `Request` accessors take on every call.
     *
     * @param class-string $className
     */
    public static function of(string $className): RequestMetadata
    {
        $shared = self::$shared ??= new self();

        return $shared->cache[$className] ??= $shared->build($className);
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
            // Children join the transform pass only when their class has
            // something to run there.
            $transformed = array_keys(array_filter(
                $properties,
                fn(PropertyMetadata $property): bool => $property->decidedInTransform()
                    || ($property->buildsChildren() && $this->needsTransform($property->items ?? $property->nested))
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
        $companion = $this->firstPresent(
            $property,
            [...self::INPUT_ATTRIBUTES, Generator::class, Group::class, Exclude::class]
        );

        if ($companion !== null) {
            throw ConfigurationException::ignoreConflict($className, $property->getName(), $companion);
        }
    }

    /**
     * The short name of the first of the attributes the property carries.
     *
     * @param list<class-string> $attributes
     */
    private function firstPresent(ReflectionProperty $property, array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if ($property->getAttributes($attribute) !== []) {
                return (new ReflectionClass($attribute))->getShortName();
            }
        }

        return null;
    }

    /**
     * Whether the DTOs of a nested or `#[Items]` class have anything to run in
     * the transform pass. A class still being built — a self-referencing
     * tree — is assumed to.
     *
     * @param class-string<Request>|null $class
     */
    private function needsTransform(?string $class): bool
    {
        return $class !== null && (isset($this->building[$class]) || $this->get($class)->transformed !== []);
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

        $type = $property->getType();
        [$phpType, $isNullable, $classType] = $this->resolvePropertyType($type);
        // Lower-cased names of the type's named members; null when untyped.
        $members = $phpType === null ? null : explode('|', strtolower($phpType));
        $appSupplied = $source === FieldSource::Context || $generator !== null;

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
            $compatible = [...self::CAST_COMPATIBILITY[$castType], 'mixed'];
            if ($members !== null && array_intersect($members, $compatible) === []) {
                throw ConfigurationException::castTypeMismatch($className, $name, $cast->type, (string) $phpType);
            }
        }

        if ($dateFormat !== null) {
            $conflict = $this->firstPresent($property, [Cast::class, Caster::class, Items::class]);
            if ($conflict !== null) {
                throw ConfigurationException::dateFormatConflict($className, $name, $conflict);
            }
        }

        $dateClass = $this->resolveDateClass($dateFormat, $cast, $caster, $phpType, $classType, $className, $name);

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

            $conflict = $this->firstPresent($property, [Cast::class, Caster::class]);
            if ($conflict !== null) {
                throw ConfigurationException::itemsCastConflict($className, $name, $conflict);
            }
        }

        [$effectiveCastType, $enumClass] = $this->resolveCast($castType, $customCasterClass, $phpType, $classType);

        // A property typed with a Request subclass is a nested DTO — unless a
        // caster produces the object itself, or the application supplies it
        // (a generator, context).
        $nested = null;
        if (
            $classType !== null
            && $caster === null
            && !$appSupplied
            && is_subclass_of($classType, Request::class)
        ) {
            $this->validateChildClass($classType, $className, $name, 'nested');
            $nested = $classType;
        }

        // Fail fast on a type nothing can produce from request input — a
        // class, `object`, an intersection, a union of classes: otherwise the
        // first request carrying the field ends in a TypeError.
        $producedOtherwise = $nested !== null
            || $dateClass !== null
            || $enumClass !== null
            || $effectiveCastType !== null
            || $customCasterClass !== null
            || $postProcessorKind !== null
            || $appSupplied;
        $holdsInput = $members === null || array_intersect($members, self::INPUT_TYPES) !== [];

        if (!$producedOtherwise && $preProcessorKind === null && !$holdsInput) {
            throw ConfigurationException::unproducibleType($className, $name, (string) $phpType);
        }

        // A type only a typed pre-processor produces: input its guard turns
        // away can never become the value — the client sent the wrong type,
        // reported with the rule for the type the pre-processor takes. One
        // that takes no type input can have would never run at all.
        $preProcessorFailure = null;
        if (!$holdsInput && !$producedOtherwise && $preProcessorGuard !== null) {
            $preProcessorFailure = $this->typeRule($preProcessorGuard->members())
                ?? throw ConfigurationException::preProcessorTakesNoInput(
                    $className,
                    $name,
                    (string) $preProcess?->handler
                );
        }

        // An input type no cast handles — a union, a standalone `true` /
        // `false` — is cast member by member; `mixed` takes anything as it is.
        $memberCast = $members !== null
            && $members !== ['mixed']
            && $holdsInput
            && $effectiveCastType === null
            && $enumClass === null
            && $dateClass === null
            && $customCasterClass === null;
        [$unionCasts, $unionFailure] = $memberCast ? $this->unionCasts($members) : [null, null];

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
            customCasterClass: $customCasterClass,
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
            // A list the application supplies holds its DTOs already: they are
            // checked, not built.
            items: $appSupplied ? null : $items?->class,
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
            // What the application supplies is kept as it is: never
            // auto-trimmed, and left out by a class-level #[EmptyToNull].
            emptyToNull: $emptyToNull !== null || ($classEmptyToNull && !$appSupplied),
            noTrim: $noTrim !== null || $classNoTrim || $appSupplied,
            typeGuard: $memberCast ? ParameterTypeGuard::fromType($type) : null,
            unionCasts: $unionCasts,
            suppliedItems: $appSupplied ? $items?->class : null,
            preProcessorFailure: $preProcessorFailure,
            holdsInput: $holdsInput,
        );
    }

    /**
     * For a type cast member by member (`int|float`, `int|string`, `true`):
     * the scalar members a value of none of its types is converted to, and
     * the error when none fits. A literal `true` / `false` member is reached
     * through the bool cast; the type's guard then keeps only the literal's
     * own value.
     *
     * @param list<string> $members Lower-cased member names.
     * @return array{0: list<string>, 1: array{rule: string}|null}
     */
    private function unionCasts(array $members): array
    {
        $casts = array_values(array_intersect(self::UNION_CASTS, $members));
        if (!in_array('bool', $casts, true) && array_intersect(['true', 'false'], $members) !== []) {
            $casts[] = 'bool';
        }

        return [$casts, $this->typeRule($members)];
    }

    /**
     * The rule a value fails that is none of the types — named after the
     * widest of them. Null when no member is a type input can have.
     *
     * @param list<string> $members Lower-cased type names.
     * @return array{rule: string}|null
     */
    private function typeRule(array $members): ?array
    {
        foreach (self::TYPE_RULES as $member => $rule) {
            if (in_array($member, $members, true)) {
                return ['rule' => $rule];
            }
        }

        return null;
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
        ?string $phpType,
        ?string $classType,
        string $className,
        string $name,
    ): ?string {
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
     * an array or iterable cast, which accepts any input.
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
            return ['rule' => 'in', 'params' => array_map('strval', array_column($enumClass::cases(), 'value'))];
        }

        if ($dateClass !== null) {
            return $dateFormat === null ? ['rule' => 'date'] : ['rule' => 'date_format', 'params' => [$dateFormat]];
        }

        return match ($effectiveCastType) {
            'int', 'float', 'bool', 'string' => ['rule' => self::TYPE_RULES[$effectiveCastType]],
            'json' => ['rule' => 'json'],
            default => null, // array / iterable read any input
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
        $conflict = $this->firstPresent($property, self::INPUT_ATTRIBUTES);

        if ($conflict !== null) {
            throw ConfigurationException::generatorConflict($className, $propertyName, $conflict);
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
     * Resolve the built-in cast. Returns [effectiveCastType, enumClass].
     * Dates are resolved separately (`resolveDateClass`).
     *
     * - explicit `#[Caster(...)]`              → [null, null] — the caster decides
     * - explicit `#[Cast('int'|…|'json')]`    → [castType, null]
     * - no attribute, built-in property type   → [propertyType, null]
     * - no attribute, `iterable`               → ['iterable', null]
     * - no attribute, BackedEnum property type → [null, enumClass]
     * - anything else                          → [null, null]
     *
     * @return array{0: ?string, 1: ?class-string<BackedEnum>}
     */
    private function resolveCast(
        ?string $castType,
        ?string $customCasterClass,
        ?string $propertyType,
        ?string $classType
    ): array {
        if ($customCasterClass !== null) {
            return [null, null];
        }

        if ($castType !== null) {
            // castType is validated upstream to be a built-in.
            return [$castType, null];
        }

        if ($propertyType !== null && BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null];
        }

        if ($propertyType === 'iterable') {
            return ['iterable', null];
        }

        if ($classType !== null && is_subclass_of($classType, BackedEnum::class)) {
            return [null, $classType];
        }

        return [null, null];
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
