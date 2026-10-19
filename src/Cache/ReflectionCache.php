<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use BackedEnum;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionProperty;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Attributes\Items;
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
     * Built-in cast type → acceptable PHP types for the target property.
     *
     * Used by `isCastCompatible`. Keys mirror the entries in
     * `BuiltInCaster::BUILT_IN_TYPES`; `datetime[:...]` is handled
     * separately because the spec has a variable suffix. An int result
     * widens losslessly into a float property, a float result does not
     * narrow into an int one; `mixed` accepts every cast.
     */
    private const array CAST_COMPATIBILITY = [
        'int'     => ['int', 'float'],
        'integer' => ['int', 'float'],
        'float'   => ['float'],
        'double'  => ['float'],
        'bool'    => ['bool'],
        'boolean' => ['bool'],
        'string'  => ['string'],
        'array'   => ['array', 'iterable'],
    ];

    /**
     * DateTime type (lower-case) → whether it needs an immutable instance:
     * true = DateTimeImmutable only, false = DateTime only, null = either.
     * Drives the type-driven spec, the adaptation of an explicit spec and its
     * compatibility check.
     */
    private const array DATETIME_TYPES = [
        'datetimeimmutable' => true,
        'datetime'          => false,
        'datetimeinterface' => null,
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
            $properties = [];

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                if (!empty($property->getAttributes(Ignore::class))) {
                    $this->assertIgnoreHasNoCompanions($property, $className);
                    continue;
                }

                $metadata = $this->buildPropertyMetadata($property, $className);
                $properties[$metadata->name] = $metadata;
            }

            $generated = array_keys(array_filter(
                $properties,
                static fn(PropertyMetadata $property): bool => $property->generator !== null
            ));
            $transformed = array_keys(array_filter(
                $properties,
                static fn(PropertyMetadata $property): bool => $property->postProcessorKind !== null
                    || $property->customCasterClass !== null
                    || $property->items !== null
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
     * @param class-string $requestClass The class being built — static-method
     *                                   processors are looked up (and later called) on it,
     *                                   which may be a subclass of the declaring class.
     */
    private function buildPropertyMetadata(ReflectionProperty $property, string $requestClass): PropertyMetadata
    {
        $name = $property->getName();
        $className = $property->getDeclaringClass()->getName();

        $validate = $this->firstAttribute($property, Validate::class);
        $cast = $this->firstAttribute($property, Cast::class);
        $caster = $this->firstAttribute($property, Caster::class);
        $preProcess = $this->firstAttribute($property, PreProcess::class);
        $postProcess = $this->firstAttribute($property, PostProcess::class);
        $generator = $this->firstAttribute($property, Generator::class);
        $items = $this->firstAttribute($property, Items::class);
        $group = $this->firstAttribute($property, Group::class);
        $excludeAttr = $this->firstAttribute($property, Exclude::class);

        [$source, $sourceKey, $pathSegments] = $this->detectSource($property, $name, $className);

        $rules = $validate?->rules;
        $castType = $cast?->type;
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

        $adaptedCastType = null;
        if ($castType !== null) {
            if (!BuiltInCaster::isBuiltIn($castType)) {
                if (class_exists($castType)) {
                    throw ConfigurationException::castExpectsBuiltInType($className, $name, $castType);
                }
                throw ConfigurationException::unknownCastType($className, $name, $castType);
            }
            $adaptedCastType = $this->adaptDateTimeSpec($castType, $classType);
            if ($phpType !== null && !$this->isCastCompatible($adaptedCastType, $phpType)) {
                throw ConfigurationException::castTypeMismatch($className, $name, $castType, $phpType);
            }
        }

        if ($customCasterClass !== null && !$this->isCasterClass($customCasterClass)) {
            throw ConfigurationException::invalidCaster($className, $name, $customCasterClass);
        }

        [$preProcessorKind, $preProcessorGuard] =
            $this->classifyProcessor($preProcess?->handler, $requestClass, $className, $name, 'preProcess');
        [$postProcessorKind, $postProcessorGuard] =
            $this->classifyProcessor($postProcess?->handler, $requestClass, $className, $name, 'postProcess');

        if ($items !== null) {
            $this->validateItems($items->class, $className, $name);

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
            $adaptedCastType,
            $customCasterClass,
            $phpType,
            $classType
        );

        // An array is garbage for an int/float/bool. A string JSON-encodes it
        // (documented cast behaviour) — unless it came from a #[FromBody]
        // dot-path, where it means the path stops at a sub-object.
        $rejectsArrayInput = $effectiveCastType !== null
            && (
                BuiltInCaster::rejectsArray($effectiveCastType)
                || ($pathSegments !== null && strtolower($effectiveCastType) === 'string')
            );

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
            rejectsArrayInput: $rejectsArrayInput,
            castFailure: $this->castFailure($effectiveCastType, $enumClass),
            groupMapTo: $group?->mapTo,
        );
    }

    /**
     * The validation error reported when a built-in cast turns non-empty input
     * into null. Null for casts that cannot fail (int, float, bool, string, array).
     *
     * @param class-string<BackedEnum>|null $enumClass
     * @return array{rule: string, params?: list<string>}|null
     */
    private function castFailure(?string $effectiveCastType, ?string $enumClass): ?array
    {
        if ($enumClass !== null) {
            return [
                'rule' => 'in',
                'params' => array_map(
                    static fn(BackedEnum $case): string => (string) $case->value,
                    $enumClass::cases()
                ),
            ];
        }

        if ($effectiveCastType === null || !BuiltInCaster::isDateTime($effectiveCastType)) {
            return null;
        }

        $format = BuiltInCaster::parseDateTimeSpec($effectiveCastType)['format'];

        return $format === null ? ['rule' => 'date'] : ['rule' => 'date_format', 'params' => [$format]];
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
     *
     * - explicit `#[Caster(...)]`              → [null, casterClass, null]
     * - explicit `#[Cast('built-in')]`         → [castType, null, null] (already adapted to the property type)
     * - no attribute, built-in property type   → [propertyType, null, null]
     * - no attribute, DateTime family          → ['datetime' | 'datetime:immutable', null, null]
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
            // castType is validated upstream to be a built-in.
            return [$castType, null, null];
        }

        if ($propertyType === null) {
            return [null, null, null];
        }

        if (BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null, null];
        }

        if ($classType === null) {
            return [null, null, null];
        }

        $dateTimeType = strtolower($classType);
        if (array_key_exists($dateTimeType, self::DATETIME_TYPES)) {
            return [self::DATETIME_TYPES[$dateTimeType] === true ? 'datetime:immutable' : 'datetime', null, null];
        }

        if (is_subclass_of($classType, BackedEnum::class)) {
            return [null, null, $classType];
        }

        return [null, null, null];
    }

    /**
     * A mutable `datetime[:format]` cast on a property typed exactly
     * `DateTimeImmutable` would produce an unassignable `DateTime` — produce
     * the immutable class the property demands instead.
     */
    private function adaptDateTimeSpec(string $castType, ?string $classType): string
    {
        if (
            $classType === null
            || (self::DATETIME_TYPES[strtolower($classType)] ?? null) !== true
            || !BuiltInCaster::isDateTime($castType)
        ) {
            return $castType;
        }

        $spec = BuiltInCaster::parseDateTimeSpec($castType);

        return $spec['immutable']
            ? $castType
            : 'datetime:immutable' . ($spec['format'] !== null ? ':' . $spec['format'] : '');
    }

    /**
     * Built-in cast compatibility check. castType is guaranteed built-in by
     * upstream validation and already adapted to the property type.
     */
    private function isCastCompatible(string $castType, string $propertyType): bool
    {
        if (BuiltInCaster::isDateTime($castType)) {
            $immutable = BuiltInCaster::parseDateTimeSpec($castType)['immutable'];
            $allowedTypes = ['object'];
            foreach (self::DATETIME_TYPES as $type => $needsImmutable) {
                if ($needsImmutable === null || $needsImmutable === $immutable) {
                    $allowedTypes[] = $type;
                }
            }

            return $this->isTypeInUnion($propertyType, $allowedTypes);
        }

        // Upstream guard in buildPropertyMetadata throws on anything that is
        // not a built-in, so the lookup always hits. `?? []` is a defensive
        // no-match fallback that satisfies the type-checker without dead code.
        return $this->isTypeInUnion(
            $propertyType,
            self::CAST_COMPATIBILITY[strtolower($castType)] ?? []
        );
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

    private function validateItems(string $items, string $className, string $propertyName): void
    {
        if (!class_exists($items)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'class does not exist');
        }

        if (!is_subclass_of($items, Request::class)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $items, 'must extend Request');
        }

        if (!(new ReflectionClass($items))->isInstantiable()) {
            throw ConfigurationException::abstractClass($className, $propertyName, 'Items', $items);
        }

        // Fail fast on the item class too — otherwise its misconfiguration
        // surfaces only when a request first carries items. A class already
        // being built (self-referencing tree) is validated by that build.
        if (!isset($this->building[$items])) {
            $this->get($items);
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
