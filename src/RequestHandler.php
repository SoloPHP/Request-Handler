<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
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
use Solo\RequestHandler\Pipeline\ProvidedFields;
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
        /** @var array<string, true> $unprocessed Input the only pre-processor of the type skipped. */
        $unprocessed = [];

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
                // The only producer of the property's type gets it converted
                // first, or its `preProcessorFailure` once validation had its
                // say — see PropertyMetadata.
                $input = $value;
                $guard = $property->preProcessorGuard;
                if ($property->preProcessorFailure !== null && $guard !== null) {
                    $input = $this->builtInCaster->castToMembers($guard, $value);
                }

                if ($input === null) {
                    $unprocessed[$name] = true;
                } elseif ($property->preProcessorKind !== null) {
                    try {
                        $value = $this->runHook(
                            $property->preProcessor,
                            $property->preProcessorKind,
                            $guard,
                            [],
                            $input,
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

            if (isset($errors[$name]) || $property->decidedInTransform()) {
                continue;
            }

            if (isset($unprocessed[$name])) {
                /** @var array{rule: string} $failure */
                $failure = $property->preProcessorFailure;
                $errors[$name] = [$failure];
                continue;
            }

            // The application's value as it is — an #[Items] list included,
            // whose DTOs it has built already.
            if ($property->source === FieldSource::Context) {
                $values[$name] = $this->castTrusted($property, $value, $className);
                continue;
            }

            // "" reads as the property's own reader reads it, decided once in
            // its metadata: no value, an empty list, or "" itself.
            if ($value === '') {
                $values[$name] = $value = $property->blank;
            }

            if ($property->buildsChildren()) {
                if ($value !== null) {
                    $childErrors[$name] = [];
                    $children[$name] = $this->resolveChildren($property, $value, $bags->context, $childErrors[$name]);
                } elseif ($this->requiresValue($property)) {
                    $errors[$name] = [['rule' => 'required']];
                }
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

            if ($property->decidedInTransform()) {
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
                // The hook decides the value; populateInstance() checks that
                // the property can hold it.
                $values[$name] = $value;
            }

            if (!$property->buildsChildren()) {
                continue;
            }

            $childErrors[$name] = [];
            // Children built from a post-processor's output exist only now.
            if ($property->decidedInTransform() && $value !== null) {
                $children[$name] = $this->resolveChildren(
                    $property,
                    $value,
                    $resolved->bags->context,
                    $childErrors[$name]
                );
            }
            if ($childErrors[$name] === []) {
                foreach ($children[$name] ?? [] as $path => $child) {
                    $children[$name][$path] = $this->transformChild($child, (string) $path, $childErrors[$name]);
                }
            }
        }

        $errors = $this->orderErrors($metadata, $errors, $childErrors);

        return new ResolvedRequest($metadata, $resolved->bags, $values, $children, $errors);
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
            $hydrated = [];
            foreach ($children as $path => $child) {
                $item = $this->hydrateChild($child, (string) $path, $errors);
                if ($item !== null) {
                    $hydrated[] = $item;
                }
            }
            // A nested DTO is the one child; #[Items], the list of them.
            $values[$name] = $metadata->properties[$name]->nested !== null ? ($hydrated[0] ?? null) : $hydrated;
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
     * Resolve the children of a nested-DTO or `#[Items]` property, keyed by
     * input path — `address`, or `lines.0`, `lines.1`, … — the key later
     * passes report errors under. A child that cannot be built is left out
     * and its error recorded.
     *
     * @param array<string, mixed> $context
     * @param ValidationErrors     $errors Receives path-keyed child errors.
     * @return array<string, ResolvedRequest>
     */
    private function resolveChildren(
        PropertyMetadata $property,
        mixed $value,
        array $context,
        array &$errors,
    ): array {
        $path = $property->sourceKey;

        if ($property->nested !== null) {
            $child = $this->resolveChild($property->nested, $value, $path, $context, $errors);
            return $child !== null ? [$path => $child] : [];
        }

        if (!is_array($value)) {
            $errors[$path] = [['rule' => 'array']];
            return [];
        }

        /** @var class-string<Request> $itemsClass */
        $itemsClass = $property->items;
        $children = [];
        foreach ($value as $index => $itemData) {
            $itemPath = "{$path}.{$index}";
            $child = $this->resolveChild($itemsClass, $itemData, $itemPath, $context, $errors);
            if ($child !== null) {
                $children[$itemPath] = $child;
            }
        }

        return $children;
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

        // Each reader here has its `blank` arm in ReflectionCache.
        $cast = match (true) {
            $property->enumClass !== null => $this->builtInCaster->castEnum($property->enumClass, $value),
            $property->dateClass !== null => $this->builtInCaster->castDate(
                $property->dateClass,
                $property->dateFormat,
                $value
            ),
            $property->effectiveCastType !== null => $this->builtInCaster->cast($property->effectiveCastType, $value),
            $property->memberGuard !== null => $this->builtInCaster->castToMembers($property->memberGuard, $value),
            default => $value,
        };

        // "" is read like any other value: what the client's "" means was
        // settled by the property's `blank`; the application's is a value.
        if ($cast === null) {
            $error = $property->castFailure;
        }

        return $cast;
    }

    /**
     * Cast a value the application supplied (context, generator). One that
     * does not fit the declared type is a programming error, not a client
     * mistake, so it raises instead of becoming a validation error. A
     * context value is checked in the resolve pass — after the
     * pre-processors, before any post-processor, caster or generator runs —
     * a generated one as its generator returns. What a hook makes of a value
     * is checked when populateInstance() assigns it.
     */
    private function castTrusted(PropertyMetadata $property, mixed $value, string $className): mixed
    {
        $cast = $this->castBuiltIn($value, $property, $error);

        if ($error !== null) {
            throw new \UnexpectedValueException(sprintf(
                "%s does not fit %s::\$%s (%s).",
                $this->describeValue($property),
                $className,
                $property->name,
                $error['rule'],
            ));
        }

        if ($cast === null) {
            if ($this->requiresValue($property)) {
                throw $this->emptyValue($property, $className);
            }
        } elseif ($property->typeGuard?->accepts($cast) === false) {
            throw $this->wrongType($property, $cast, $className);
        } elseif ($property->suppliedItems !== null) {
            $this->checkSuppliedItems($property, $property->suppliedItems, $cast, $className);
        }

        return $cast;
    }

    private function emptyValue(PropertyMetadata $property, string $className): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            "%s is empty, but %s::\$%s is neither nullable nor has a default.",
            $this->describeValue($property),
            $className,
            $property->name,
        ));
    }

    private function wrongType(
        PropertyMetadata $property,
        mixed $value,
        string $className,
        ?\Throwable $previous = null,
    ): \UnexpectedValueException {
        return new \UnexpectedValueException(sprintf(
            "%s is %s, which does not fit %s::\$%s (%s).",
            $this->describeValue($property),
            get_debug_type($value),
            $className,
            $property->name,
            (string) $property->type,
        ), 0, $previous);
    }

    /**
     * Where a value that does not fit its property came from — for the
     * exception: the hook that returned it, the application, or the input.
     */
    private function describeValue(PropertyMetadata $property): string
    {
        $what = match (true) {
            $property->generator !== null => "Value generated by {$property->generator}",
            $property->source === FieldSource::Context => "Context value '{$property->sourceKey}'",
            default => "Value of '{$property->sourceKey}'",
        };

        return match (true) {
            $property->postProcessorKind !== null => "{$what} after #[PostProcess({$property->postProcessor})]",
            $property->customCasterClass !== null => "{$what} after #[Caster({$property->customCasterClass})]",
            default => $what,
        };
    }

    /**
     * `#[Items]` on a list the application supplies declares the class of the
     * DTOs it built: each element is checked, none is built from an array.
     *
     * @param class-string<Request> $itemClass
     */
    private function checkSuppliedItems(
        PropertyMetadata $property,
        string $itemClass,
        mixed $value,
        string $className,
    ): void {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (!$item instanceof $itemClass) {
                throw new \UnexpectedValueException(sprintf(
                    "%s holds %s at key %s, but %s::\$%s lists %s.",
                    $this->describeValue($property),
                    get_debug_type($item),
                    $key,
                    $className,
                    $property->name,
                    $itemClass,
                ));
            }
        }
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

        // Lists sharing an input key — two properties reading it, or a flat
        // #[FromBody('a.b')] next to a nested `a` — are merged, never dropped.
        $ordered = [];
        foreach ($metadata->properties as $name => $property) {
            if (isset($errors[$name])) {
                $ordered = $this->mergeErrors($ordered, [$property->sourceKey => $errors[$name]]);
                unset($errors[$name]);
            }
            $ordered = $this->mergeErrors($ordered, $childErrors[$name] ?? []);
        }

        // Keys reported for something other than a property.
        return $this->mergeErrors($ordered, $errors);
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

            // Every final value passes here. Client input the property
            // cannot take was a validation error or no value already, and
            // context and generated values were checked when cast: a value
            // that still does not fit is what a hook returned — a programming
            // error rather than a TypeError or an uninitialised property. A
            // hook's result is assigned as PHP assigns any value.
            if ($value === null && !$property->isNullable) {
                if (!$property->hasDefault) {
                    throw $this->emptyValue($property, $metadata->className);
                }
                // null resets a defaulted property to its default
            } else {
                // A context list without a hook was checked by castTrusted().
                if ($property->suppliedItems !== null && $property->decidedInTransform()) {
                    $this->checkSuppliedItems($property, $property->suppliedItems, $value, $metadata->className);
                }

                try {
                    $property->reflection->setValue($instance, $value);
                } catch (\TypeError $e) {
                    throw $this->wrongType($property, $value, $metadata->className, $e);
                }
            }
        }

        // Absent keys never reach $data: what was assigned here — or reset to
        // its default — is what the request supplied.
        ProvidedFields::record($instance, $metadata, $data);

        return $instance;
    }
}
