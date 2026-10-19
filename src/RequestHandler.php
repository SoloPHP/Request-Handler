<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Cache\ProcessorKind;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\Pipeline\ParameterTypeGuard;
use Solo\RequestHandler\Pipeline\ProcessorRegistry;
use Solo\RequestHandler\Pipeline\ResolvedRequest;
use Solo\RequestHandler\Schema\SchemaBuilder;

/**
 * Factory for creating request DTOs from HTTP requests.
 *
 * Each property's source (body / query / route / context) is decided by its
 * attributes (`#[FromRoute]`, `#[FromContext]`). Without a source attribute,
 * the default is determined by the `handle*` method invoked: `handleBody` /
 * `handleArray` default to body; `handleQuery` defaults to query.
 *
 * Example:
 * ```php
 * $dto = $requestHandler->handleBody(
 *     UpdateProductRequest::class,
 *     $request,
 *     route:   ['id' => $productId],
 *     context: ['authenticatedUserId' => $auth->id()],
 * );
 * ```
 *
 * @phpstan-import-type ValidationErrors from ValidationException
 * @phpstan-import-type FieldSchema from SchemaBuilder
 */
final class RequestHandler
{
    private readonly ReflectionCache $cache;
    private readonly BuiltInCaster $builtInCaster;
    private readonly ProcessorRegistry $processors;
    /** `Request::$provided`, written when a DTO is populated. */
    private readonly ReflectionProperty $provided;

    /**
     * @param ContainerInterface|null $container Resolves processor, caster and
     *        generator classes by class name — for those with constructor
     *        dependencies. An instance passed to `register()` takes precedence.
     */
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly bool $autoTrim = true,
        ?ContainerInterface $container = null,
    ) {
        $this->cache = ReflectionCache::shared();
        $this->builtInCaster = new BuiltInCaster();
        $this->processors = new ProcessorRegistry($container);
        $this->provided = new ReflectionProperty(Request::class, 'provided');
    }

    /**
     * Register a processor/caster/generator instance for dependency injection.
     * It takes precedence over the container's entry for the class.
     *
     * @param class-string $className
     */
    public function register(string $className, object $instance): self
    {
        $this->processors->register($className, $instance);
        return $this;
    }

    /**
     * Create a Request DTO from request body (POST/PUT/PATCH requests).
     *
     * @template T of Request
     * @param class-string<T>      $className
     * @param array<string, mixed> $route   Consumed by #[FromRoute].
     * @param array<string, mixed> $context Consumed by #[FromContext].
     * @return T
     */
    public function handleBody(
        string $className,
        ServerRequestInterface $request,
        array $route = [],
        array $context = [],
    ): Request {
        return $this->processRawData(
            $className,
            FieldSource::Body,
            InputBags::fromRequest($request, $route, $context),
        );
    }

    /**
     * Create a Request DTO from query parameters (GET requests).
     *
     * @template T of Request
     * @param class-string<T>      $className
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     * @return T
     */
    public function handleQuery(
        string $className,
        ServerRequestInterface $request,
        array $route = [],
        array $context = [],
    ): Request {
        return $this->processRawData(
            $className,
            FieldSource::Query,
            InputBags::fromRequest($request, $route, $context),
        );
    }

    /**
     * Create a Request DTO from a raw array. The array plays the role of the
     * body bag; query is empty.
     *
     * @template T of Request
     * @param class-string<T>      $className
     * @param array<string, mixed> $data
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     * @return T
     */
    public function handleArray(
        string $className,
        array $data,
        array $route = [],
        array $context = [],
    ): Request {
        return $this->processRawData(
            $className,
            FieldSource::Body,
            InputBags::fromArray($data, $route, $context),
        );
    }

    /**
     * Build the JSON-serializable input contract of a request class.
     *
     * One entry per field the client sends, keyed by its input key (the key
     * validation errors use): type, nullability, requiredness — including
     * conditional `required_if` — the normalized rule list, the default, and
     * where relevant the date format, enum cases and nested schemas, so a
     * frontend can treat the backend as the single source of truth. Generated
     * and `#[FromContext]` properties are not part of it. Read-only: it does
     * not process any HTTP input.
     *
     * @param  class-string $className
     * @return array<string, FieldSchema>
     */
    public function schema(string $className): array
    {
        return (new SchemaBuilder($this->cache))->build($this->cache->get($className));
    }

    /**
     * Three passes, each only once the previous one left no error anywhere in
     * the tree: resolve() reads and validates client input, transform() runs
     * the application's post-processors and custom casters, hydrate() runs
     * generators and builds the DTOs. Side effects therefore never happen for
     * a request that is rejected.
     *
     * @template T of Request
     * @param class-string<T> $className
     * @return T
     */
    private function processRawData(string $className, FieldSource $defaultSource, InputBags $bags): Request
    {
        $resolved = $this->resolve($className, $defaultSource, $bags);

        if ($resolved->errors === []) {
            $resolved = $this->transform($resolved);
        }

        if ($resolved->errors !== []) {
            throw new ValidationException($resolved->errors);
        }

        /** @var T */
        return $this->hydrate($resolved);
    }

    /**
     * First pass: read, pre-process, validate and cast — everything that can
     * fail on client input. Every error in the tree is collected, nested items
     * included.
     *
     * @param class-string<Request> $className
     */
    private function resolve(string $className, FieldSource $defaultSource, InputBags $bags): ResolvedRequest
    {
        $metadata = $this->cache->get($className);

        /** @var array<string, mixed>  $validationData */
        $validationData = [];
        /** @var array<string, string> $validationRules */
        $validationRules = [];
        /** @var array<string, mixed>  $values */
        $values = [];
        /** @var ValidationErrors $errors */
        $errors = [];

        foreach ($metadata->properties as $name => $property) {
            if ($property->generator !== null) {
                continue;
            }

            $hasValueInRequest = false;
            $value = $bags->lookup($property, $defaultSource, $hasValueInRequest);

            if (!$hasValueInRequest) {
                // Missing key — enrol into validation only for properties whose
                // rules can fire without a value: literal 'required' and the
                // conditional-presence family (required_if, required_with, ...).
                // Those may carry a default (`?string $x = null` with
                // `required_if:kind,company`): the absence itself is what has to
                // be validated, with the default as the value.
                if ($property->validatesWhenAbsent && $property->validationRules !== null) {
                    $validationData[$name] = $property->defaultValue;
                    $validationRules[$name] = $property->validationRules;
                }
                continue;
            }

            if ($this->autoTrim && !$property->noTrim && is_string($value)) {
                $value = trim($value);
            }
            // #[EmptyToNull]: a blank string is no value — for the validator
            // and the property alike.
            if ($property->emptyToNull && is_string($value) && trim($value) === '') {
                $value = null;
            }

            if ($value === null || $value === '') {
                $values[$name] = $value === '' || $property->isNullable ? $value : $property->defaultValue;
            } else {
                // Pre-processors see raw client input; runHook() skips a
                // function/static-method handler that cannot take its type.
                if ($property->preProcessorKind !== null) {
                    try {
                        $value = $this->runHook(
                            $property->preProcessor,
                            $property->preProcessorKind,
                            $property->preProcessorGuard,
                            [],
                            $value,
                            $className,
                            $bags,
                        );
                    } catch (ValidationException $e) {
                        $errors = $this->mergeErrors($errors, $e->getErrors());
                        continue;
                    }
                }
                $values[$name] = $value;
            }

            // Every value the request carries is part of the validation
            // payload — cross-field rules (`same:`, `required_if:`, `{tenantId}`)
            // may reference fields that declare no rules of their own. An
            // explicit null or "" is fed in too, so 'nullable', 'in:', 'email',
            // etc. fire rather than silently passing.
            $validationData[$name] = $value;
            if ($property->validationRules !== null) {
                $validationRules[$name] = $property->validationRules;
            }
        }

        if (!empty($validationRules)) {
            $errors = $this->mergeErrors($errors, $this->validator->validate($validationData, $validationRules));
        }

        /** @var array<string, ValidationErrors> $childErrors */
        $childErrors = [];
        $children = [];

        foreach ($values as $name => $value) {
            $property = $metadata->properties[$name];

            if (isset($errors[$name]) || $this->decidedInTransform($property)) {
                continue;
            }

            if ($property->items !== null || $property->nested !== null) {
                if ($value !== null) {
                    $childErrors[$name] = [];
                    $child = $this->resolveChildren($property, $value, $bags->context, $childErrors[$name]);
                    if ($child !== null) {
                        $children[$name] = $child;
                    }
                } elseif ($this->requiresValue($property)) {
                    $errors[$name] = [['rule' => 'required']];
                }
                continue;
            }

            if ($property->source === FieldSource::Context) {
                $values[$name] = $this->castTrusted($property, $value, $className);
                continue;
            }

            $values[$name] = $value = $this->castBuiltIn($value, $property, $error);

            if ($error !== null) {
                $errors[$name] = [$error];
            } elseif ($value === null && $this->requiresValue($property)) {
                // An explicit null or "" for a property that can hold neither
                // and has no default to fall back on: the field needs a value.
                $errors[$name] = [['rule' => 'required']];
            }
        }

        $errors = $this->orderErrors($metadata, $errors, $childErrors);

        return new ResolvedRequest($metadata, $bags, $values, $children, $errors);
    }

    /**
     * Second pass, for an input free of errors: the application's
     * post-processors and custom casters, over the whole tree. Items produced
     * by a post-processor are resolved here. A ValidationException thrown by
     * that code is collected — under the child's path for nested objects —
     * rather than aborting the pass.
     */
    private function transform(ResolvedRequest $resolved): ResolvedRequest
    {
        $metadata = $resolved->metadata;
        if ($metadata->transformed === []) {
            return $resolved;
        }

        $values = $resolved->values;
        $children = $resolved->children;
        /** @var ValidationErrors $errors */
        $errors = [];
        /** @var array<string, ValidationErrors> $childErrors */
        $childErrors = [];

        foreach ($metadata->transformed as $name) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $value = $values[$name];
            $property = $metadata->properties[$name];

            if ($this->decidedInTransform($property)) {
                try {
                    if ($property->postProcessorKind !== null) {
                        $value = $this->runHook(
                            $property->postProcessor,
                            $property->postProcessorKind,
                            $property->postProcessorGuard,
                            $property->postProcessConfig,
                            $value,
                            $metadata->className,
                            $resolved->bags,
                        );
                    } elseif ($property->customCasterClass !== null && $value !== null) {
                        $value = $this->processors->invokeCaster($property->customCasterClass, $value);
                    }
                } catch (ValidationException $e) {
                    $errors = $this->mergeErrors($errors, $e->getErrors());
                    continue;
                }
                $values[$name] = $value;
            }

            if ($property->items === null && $property->nested === null) {
                continue;
            }

            $childErrors[$name] = [];
            // Children built from a post-processor's output exist only now.
            if ($this->decidedInTransform($property) && $value !== null) {
                $child = $this->resolveChildren($property, $value, $resolved->bags->context, $childErrors[$name]);
                if ($child !== null) {
                    $children[$name] = $child;
                }
            }
            if ($childErrors[$name] === [] && isset($children[$name])) {
                $children[$name] = $this->transformChildren(
                    $children[$name],
                    $property->sourceKey,
                    $childErrors[$name]
                );
            }
        }

        $errors = $this->orderErrors($metadata, $errors, $childErrors);

        return new ResolvedRequest($metadata, $resolved->bags, $values, $children, $errors);
    }

    /**
     * @param ResolvedRequest|array<int|string, ResolvedRequest> $children
     * @param ValidationErrors                                   $errors Receives path-keyed child errors.
     * @return ResolvedRequest|array<int|string, ResolvedRequest>
     */
    private function transformChildren(
        ResolvedRequest|array $children,
        string $path,
        array &$errors,
    ): ResolvedRequest|array {
        if ($children instanceof ResolvedRequest) {
            return $this->transformChild($children, $path, $errors);
        }

        foreach ($children as $index => $child) {
            $children[$index] = $this->transformChild($child, "{$path}.{$index}", $errors);
        }

        return $children;
    }

    /**
     * @param ValidationErrors $errors
     */
    private function transformChild(ResolvedRequest $child, string $path, array &$errors): ResolvedRequest
    {
        $child = $this->transform($child);
        if ($child->errors !== []) {
            $errors += $this->prefixErrors($child->errors, "{$path}.");
        }

        return $child;
    }

    /**
     * Third pass: generators, then the DTOs themselves.
     */
    private function hydrate(ResolvedRequest $resolved): Request
    {
        $metadata = $resolved->metadata;
        $values = $resolved->values;
        /** @var ValidationErrors $errors */
        $errors = [];

        foreach ($resolved->children as $name => $children) {
            $path = $metadata->properties[$name]->sourceKey;

            if ($children instanceof ResolvedRequest) {
                $values[$name] = $this->hydrateChild($children, $path, $errors);
                continue;
            }

            $hydrated = [];
            foreach ($children as $index => $child) {
                $item = $this->hydrateChild($child, "{$path}.{$index}", $errors);
                if ($item !== null) {
                    $hydrated[] = $item;
                }
            }
            $values[$name] = $hydrated;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        foreach ($metadata->generated as $name) {
            $property = $metadata->properties[$name];
            /** @var class-string<GeneratorInterface> $generatorClass */
            $generatorClass = $property->generator;
            $generated = $this->processors->runGenerator($generatorClass, $property->generatorOptions);
            $values[$name] = $this->castTrusted($property, $generated, $metadata->className);
        }

        /** @var Request $instance */
        $instance = $metadata->reflection->newInstanceWithoutConstructor();

        return $this->populateInstance($instance, $metadata, $values);
    }

    /**
     * Resolve the children of a nested-DTO or `#[Items]` property: one
     * ResolvedRequest, or the list of them keyed by input index (later passes
     * report errors under the same path; hydrate() turns it into a list).
     * Null when the nested object itself is unusable — the error is recorded.
     *
     * @param array<string, mixed> $context
     * @param ValidationErrors     $errors Receives path-keyed child errors.
     * @return ResolvedRequest|array<int|string, ResolvedRequest>|null
     */
    private function resolveChildren(
        PropertyMetadata $property,
        mixed $value,
        array $context,
        array &$errors,
    ): ResolvedRequest|array|null {
        $path = $property->sourceKey;

        if ($property->nested !== null) {
            return $this->resolveChild($property->nested, $value, $path, $context, $errors);
        }

        if (!is_array($value)) {
            $errors[$path] = [['rule' => 'array']];
            return [];
        }

        /** @var class-string<Request> $itemsClass */
        $itemsClass = $property->items;
        $items = [];
        foreach ($value as $index => $itemData) {
            $item = $this->resolveChild($itemsClass, $itemData, "{$path}.{$index}", $context, $errors);
            if ($item !== null) {
                $items[$index] = $item;
            }
        }

        return $items;
    }

    /**
     * Resolve one nested object — the value must be an array (a JSON object).
     * Only the outer context is propagated (see `InputBags::forItem()`), and
     * errors are recorded under `$path` (`address.city`, `lines.0.qty`).
     *
     * @param class-string<Request> $class
     * @param array<string, mixed>  $context
     * @param ValidationErrors      $errors
     */
    private function resolveChild(
        string $class,
        mixed $data,
        string $path,
        array $context,
        array &$errors,
    ): ?ResolvedRequest {
        if (!is_array($data)) {
            $errors[$path] = [['rule' => 'array']];
            return null;
        }

        // A ValidatorInterface implementation may throw instead of
        // returning its errors; keep those under the child's path too.
        try {
            $child = $this->resolve($class, FieldSource::Body, InputBags::forItem($data, $context));
        } catch (ValidationException $e) {
            $errors += $this->prefixErrors($e->getErrors(), "{$path}.");
            return null;
        }

        if ($child->errors !== []) {
            $errors += $this->prefixErrors($child->errors, "{$path}.");
        }

        return $child;
    }

    /**
     * Hydrate one child; a ValidationException its generators throw is
     * recorded under `$path`.
     *
     * @param ValidationErrors $errors
     */
    private function hydrateChild(ResolvedRequest $child, string $path, array &$errors): ?Request
    {
        try {
            return $this->hydrate($child);
        } catch (ValidationException $e) {
            $errors += $this->prefixErrors($e->getErrors(), "{$path}.");
            return null;
        }
    }

    /**
     * Built-in, type-driven and enum casts — the conversions that can reject
     * client input. A value that cannot be converted without loss sets
     * `$error` (the metadata's `castFailure`) instead of becoming `0`, `1`,
     * `null` or an uninitialised property.
     *
     * @param array{rule: string, params?: list<string>}|null     $error
     * @param-out array{rule: string, params?: list<string>}|null $error
     */
    private function castBuiltIn(mixed $value, PropertyMetadata $property, ?array &$error): mixed
    {
        $error = null;

        if ($value === null) {
            return null;
        }

        $cast = match (true) {
            $property->enumClass !== null => $this->builtInCaster->castEnum($property->enumClass, $value),
            $property->dateClass !== null => $this->builtInCaster->castDate(
                $property->dateClass,
                $property->dateFormat,
                $value
            ),
            $property->effectiveCastType !== null => $this->builtInCaster->cast($property->effectiveCastType, $value),
            default => $value,
        };

        if ($cast === null && $value !== '') {
            $error = $property->castFailure;
        }

        return $cast;
    }

    /**
     * Cast a value the application supplied (context, generator). One that
     * does not fit the declared type is a programming error, not a client
     * mistake, so it raises instead of becoming a validation error.
     */
    private function castTrusted(PropertyMetadata $property, mixed $value, string $className): mixed
    {
        $cast = $this->castBuiltIn($value, $property, $error);

        if ($error !== null) {
            throw new \UnexpectedValueException(sprintf(
                "%s does not fit %s::\$%s (%s).",
                $property->generator !== null
                    ? "Value generated by {$property->generator}"
                    : "Context value '{$property->sourceKey}'",
                $className,
                $property->name,
                $error['rule'],
            ));
        }

        return $cast;
    }

    /**
     * Whether a post-processor or custom caster decides the final value —
     * and, for nested objects and items, what they are built from. Those run
     * in transform().
     */
    private function decidedInTransform(PropertyMetadata $property): bool
    {
        return $property->postProcessorKind !== null || $property->customCasterClass !== null;
    }

    /**
     * A property that can hold neither null nor fall back to a default.
     */
    private function requiresValue(PropertyMetadata $property): bool
    {
        return !$property->isNullable && !$property->hasDefault;
    }

    /**
     * Run a pre/post-process handler — or return the value unchanged when
     * there is none, or when its guard rejects the value's type.
     *
     * @param array<string, mixed> $config
     * @param class-string         $className
     */
    private function runHook(
        ?string $handler,
        ?ProcessorKind $kind,
        ?ParameterTypeGuard $guard,
        array $config,
        mixed $value,
        string $className,
        InputBags $bags,
    ): mixed {
        if ($handler === null || $kind === null || !($guard?->accepts($value) ?? true)) {
            return $value;
        }

        return $this->processors->runProcessor(
            $handler,
            $kind,
            $value,
            $className,
            new ProcessContext($config, $bags->route, $bags->context),
        );
    }

    /**
     * @param ValidationErrors        $errors
     * @param array<array-key, mixed> $more
     * @return ValidationErrors
     */
    private function mergeErrors(array $errors, array $more): array
    {
        foreach ($more as $field => $messages) {
            /** @var list<array{rule: string, params?: string[]}> $messages */
            $errors[(string) $field] = array_merge($errors[(string) $field] ?? [], $messages);
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $errors
     * @return ValidationErrors
     */
    private function prefixErrors(array $errors, string $prefix): array
    {
        $prefixed = [];
        foreach ($errors as $field => $messages) {
            /** @var list<array{rule: string, params?: string[]}> $messages */
            $prefixed[$prefix . $field] = $messages;
        }

        return $prefixed;
    }

    /**
     * Errors keyed by where the client put each value — the input key
     * (`display_name`, `customer.id`, a route parameter) rather than the PHP
     * property name the validator and handlers use — in property declaration
     * order, each nested-DTO or items property followed by its children's
     * errors: the order a form presents its fields in.
     *
     * @param ValidationErrors                $errors      Keyed by property name (or anything else).
     * @param array<string, ValidationErrors> $childErrors Already keyed by input path.
     * @return ValidationErrors
     */
    private function orderErrors(RequestMetadata $metadata, array $errors, array $childErrors): array
    {
        // Hot path: runs twice per request, almost always without errors.
        $childErrors = array_filter($childErrors);
        if ($errors === [] && $childErrors === []) {
            return [];
        }

        $ordered = [];
        foreach ($metadata->properties as $name => $property) {
            if (isset($errors[$name])) {
                $ordered[$property->sourceKey] = array_merge($ordered[$property->sourceKey] ?? [], $errors[$name]);
                unset($errors[$name]);
            }
            $ordered += $childErrors[$name] ?? [];
        }

        // Keys reported for something other than a property.
        foreach ($errors as $key => $messages) {
            $ordered[$key] = array_merge($ordered[$key] ?? [], $messages);
        }

        return $ordered;
    }

    /**
     * @template T of Request
     * @param T $instance
     * @param array<string, mixed> $data
     * @return T
     */
    private function populateInstance(Request $instance, RequestMetadata $metadata, array $data): Request
    {
        foreach ($data as $name => $value) {
            $property = $metadata->properties[$name];

            // Runtime guard: skip null assignment to non-nullable properties so the DTO
            // remains uninitialized rather than throwing TypeError during populate.
            if ($value === null && !$property->isNullable) {
                continue;
            }

            $property->reflection->setValue($instance, $value);
        }

        // Every property with a value here was supplied by the request (or
        // generated) — absent keys never reach $data. Declaration order.
        $this->provided->setValue(
            $instance,
            array_fill_keys(array_keys(array_intersect_key($metadata->properties, $data)), true)
        );

        return $instance;
    }
}
