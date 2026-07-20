<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;
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
 */
final class ReflectionCache
{
    /**
     * Built-in cast type → acceptable PHP types for the target property.
     *
     * Used by `isCastCompatible`. Keys mirror the entries in
     * `BuiltInCaster::BUILT_IN_TYPES`; `datetime[:...]` is handled
     * separately because the spec has a variable suffix.
     */
    private const CAST_COMPATIBILITY = [
        'int'     => ['int'],
        'integer' => ['int'],
        'float'   => ['float', 'int'],
        'double'  => ['float', 'int'],
        'bool'    => ['bool'],
        'boolean' => ['bool'],
        'string'  => ['string'],
        'array'   => ['array'],
    ];

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

            if (!empty($property->getAttributes(Ignore::class))) {
                $this->assertIgnoreHasNoCompanions($property, $className);
                continue;
            }

            $metadata = $this->buildPropertyMetadata($property);
            $properties[$metadata->name] = $metadata;
        }

        return new RequestMetadata($className, $properties, $reflection);
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

    private function buildPropertyMetadata(ReflectionProperty $property): PropertyMetadata
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

        [$phpType, $isNullable] = $this->resolvePropertyType($property->getType());

        $isRequired = $this->hasRule($rules, 'required');
        $validatesWhenAbsent = RuleParser::hasNamePrefix($rules, 'required');

        if ($this->hasRule($rules, 'nullable') && !$isNullable && $phpType !== null) {
            throw ConfigurationException::nullableRuleWithNonNullableType($className, $name, $phpType);
        }

        if ($isRequired && $hasDefault) {
            throw ConfigurationException::requiredWithDefault($className, $name);
        }

        if ($castType !== null) {
            if (!BuiltInCaster::isBuiltIn($castType)) {
                if (class_exists($castType)) {
                    throw ConfigurationException::castExpectsBuiltInType($className, $name, $castType);
                }
                throw ConfigurationException::unknownCastType($className, $name, $castType);
            }
            if ($phpType !== null && !$this->isCastCompatible($castType, $phpType)) {
                throw ConfigurationException::castTypeMismatch($className, $name, $castType, $phpType);
            }
        }

        if ($customCasterClass !== null && !$this->isCasterClass($customCasterClass)) {
            throw ConfigurationException::invalidCaster($className, $name, $customCasterClass);
        }

        $preProcessorKind = $this->classifyProcessor($preProcess?->handler, $className, $name, 'preProcess');
        $postProcessorKind = $this->classifyProcessor($postProcess?->handler, $className, $name, 'postProcess');

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

        [$effectiveCastType, $resolvedCasterClass] = $this->resolveCast($castType, $customCasterClass, $phpType);

        return new PropertyMetadata(
            name: $name,
            source: $source,
            sourceKey: $sourceKey,
            type: $phpType,
            isNullable: $isNullable,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            validationRules: $rules,
            castType: $castType,
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
        );
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
        if ($type instanceof \ReflectionIntersectionType) {
            $names = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), static fn($t) => $t instanceof \ReflectionNamedType)
            );
            // Intersection types cannot include `null` — they're never nullable.
            return [implode('&', $names), false];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName(), $type->allowsNull()];
        }
        return [null, true];
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
     * Validate processor and return its dispatch kind. Throws if invalid.
     *
     * Resolution order: class-string → method-on-Request → global function.
     * If the unqualified name BOTH names a global function AND exists as a
     * static method on the Request class (e.g. `'trim'`), we throw
     * `ambiguousProcessor` rather than silently picking one — the user must
     * disambiguate by renaming or by passing a class-string explicitly.
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

        $isFunc = function_exists($processor);
        $isMethod = method_exists($className, $processor);

        if ($isFunc && $isMethod) {
            throw ConfigurationException::ambiguousProcessor($className, $propertyName, $type, $processor);
        }

        if ($isMethod) {
            $method = new ReflectionMethod($className, $processor);
            if (!$method->isStatic()) {
                throw ConfigurationException::processorMethodNotStatic($className, $propertyName, $type, $processor);
            }
            return ProcessorKind::StaticMethod;
        }

        if ($isFunc) {
            return ProcessorKind::Func;
        }

        throw ConfigurationException::invalidProcessor($className, $propertyName, $type, $processor);
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
     * Resolve cast configuration. Returns [effectiveCastType, customCasterClass].
     *
     * - explicit `#[Caster(...)]`           → [null, casterClass]
     * - explicit `#[Cast('built-in')]`      → [castType, null]
     * - no attribute, type-driven default   → [propertyType, null] if built-in, else [null, null]
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCast(?string $castType, ?string $customCasterClass, ?string $propertyType): array
    {
        if ($customCasterClass !== null) {
            return [null, $customCasterClass];
        }

        if ($castType !== null) {
            // castType is validated upstream to be a built-in; safe to return as-is.
            return [$castType, null];
        }

        if ($propertyType !== null && BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null];
        }

        return [null, null];
    }

    /**
     * Built-in cast compatibility check. castType is guaranteed built-in by upstream validation.
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

        // Upstream guard in buildPropertyMetadata throws on anything that is
        // not a built-in, so the lookup always hits. `?? []` is a defensive
        // no-match fallback that satisfies the type-checker without dead code.
        return $this->isTypeInUnion(
            $propertyType,
            self::CAST_COMPATIBILITY[strtolower($castType)] ?? []
        );
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

        if (!(new ReflectionClass($items))->isInstantiable()) {
            throw ConfigurationException::abstractClass($className, $propertyName, 'Items', $items);
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
