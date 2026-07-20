<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\Pipeline\ProcessorRegistry;
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
 */
final class RequestHandler
{
    private readonly ReflectionCache $cache;
    private readonly BuiltInCaster $builtInCaster;
    private readonly ProcessorRegistry $processors;

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly bool $autoTrim = true,
    ) {
        $this->cache = new ReflectionCache();
        $this->builtInCaster = new BuiltInCaster();
        $this->processors = new ProcessorRegistry();
    }

    /**
     * Register a processor/caster/generator instance for dependency injection.
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
     * Build a JSON-serializable validation schema for a request class.
     *
     * Exposes normalized per-field validation metadata (type, nullability,
     * requiredness — including conditional `required_if` — and the full
     * normalized rule list) so a frontend can treat the backend as the single
     * source of truth. Read-only: it does not process any HTTP input.
     *
     * @param  class-string $className
     * @return array<string, array{
     *     type: ?string,
     *     nullable: bool,
     *     required: bool,
     *     requiredIf: array{field: string, value: string}|null,
     *     hasDefault: bool,
     *     exclude: bool,
     *     source: ?string,
     *     rules: list<array{name: string, args: list<string>}>
     * }>
     */
    public function schema(string $className): array
    {
        return (new SchemaBuilder())->build($this->cache->get($className));
    }

    /**
     * @template T of Request
     * @param class-string<T> $className
     * @return T
     */
    private function processRawData(string $className, FieldSource $defaultSource, InputBags $bags): Request
    {
        $metadata = $this->cache->get($className);
        /** @var T $instance */
        $instance = $metadata->reflection->newInstanceWithoutConstructor();

        /** @var array<string, mixed>  $validationData */
        $validationData = [];
        /** @var array<string, string> $validationRules */
        $validationRules = [];
        /** @var array<string, mixed>  $presentFields */
        $presentFields = [];

        foreach ($metadata->properties as $property) {
            if ($property->generator !== null) {
                /** @var class-string<GeneratorInterface> $generatorClass */
                $generatorClass = $property->generator;
                $presentFields[$property->name] = $this->processors->runGenerator(
                    $generatorClass,
                    $property->generatorOptions
                );
                continue;
            }

            $hasValueInRequest = false;
            $value = $bags->lookup($property, $defaultSource, $hasValueInRequest);

            // FromBody dot-path resolved to an array but the property is a
            // plain scalar — user pointed at a sub-object, not a leaf. Throw
            // a path-pinned error instead of letting it muddle through.
            if (
                $hasValueInRequest
                && $property->pathSegments !== null
                && is_array($value)
                && in_array($property->type, ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean'], true)
            ) {
                throw new ValidationException([$property->name => [['rule' => 'scalar']]]);
            }

            if ($this->autoTrim && is_string($value)) {
                $value = trim($value);
            }

            if (!$hasValueInRequest) {
                // A default normally means "absent is fine, nothing to check".
                // Conditional-presence rules are the exception: `?string $x = null`
                // carrying `required_if:kind,company` says the field may be
                // omitted in general but not for that kind, so the absence itself
                // is what has to be validated.
                if ($property->hasDefault && !$property->validatesWhenAbsent) {
                    continue;
                }
                // Missing key — enrol into validation only for properties whose
                // rules can fire without a value ('required' and the
                // required_* family). Other rules have nothing to validate.
                $this->collectValidation(
                    $property,
                    $property->hasDefault ? $property->defaultValue : null,
                    $validationData,
                    $validationRules
                );
                continue;
            }

            // Explicit null or "" was in the request — always feed validator if
            // any rules are declared, so 'nullable', 'in:', 'email', etc. fire
            // (rather than silently passing as they would with collectValidation,
            // which only enrols when isRequired).
            if ($value === null || $value === '') {
                $presentFields[$property->name] = $value === '' || $property->isNullable
                    ? $value
                    : ($property->hasDefault ? $property->defaultValue : null);
                if ($property->validationRules !== null) {
                    $validationData[$property->name] = $value;
                    $validationRules[$property->name] = $property->validationRules;
                }
                continue;
            }

            if ($property->preProcessorKind !== null) {
                /** @var string $handler */
                $handler = $property->preProcessor;
                $value = $this->processors->runProcessor(
                    $handler,
                    $property->preProcessorKind,
                    $value,
                    $className,
                    new ProcessContext([], $bags->route, $bags->context),
                );
            }

            if ($property->validationRules !== null) {
                $validationData[$property->name] = $value;
                $validationRules[$property->name] = $property->validationRules;
            }

            $presentFields[$property->name] = $value;
        }

        if (!empty($validationRules)) {
            $this->validate($validationData, $validationRules);
        }

        foreach ($presentFields as $name => $value) {
            $property = $metadata->properties[$name];

            if ($property->postProcessorKind === null && $property->items === null) {
                $value = $this->castValue($value, $property);
            }

            if ($property->postProcessorKind !== null) {
                /** @var string $handler */
                $handler = $property->postProcessor;
                $value = $this->processors->runProcessor(
                    $handler,
                    $property->postProcessorKind,
                    $value,
                    $className,
                    new ProcessContext($property->postProcessConfig, $bags->route, $bags->context),
                );
            }

            if ($property->items !== null && $value !== null) {
                $value = $this->processItems($property->items, $value, $name, $bags->context);
            }

            $presentFields[$name] = $value;
        }

        return $this->populateInstance($instance, $metadata, $presentFields);
    }

    /**
     * Process array items through a Request class.
     *
     * Non-array values yield a structured validation error rather than a TypeError.
     * Only the outer context is propagated (see `InputBags::forItem()`).
     *
     * @param class-string<Request> $itemsClass
     * @param array<string, mixed>  $context
     * @return array<int, Request>
     * @throws ValidationException
     */
    private function processItems(string $itemsClass, mixed $items, string $fieldName, array $context): array
    {
        if (!is_array($items)) {
            throw new ValidationException([$fieldName => [['rule' => 'array']]]);
        }

        $allErrors = [];
        $processedItems = [];

        foreach ($items as $index => $itemData) {
            if (!is_array($itemData)) {
                $allErrors["{$fieldName}.{$index}"] = [['rule' => 'array']];
                continue;
            }

            try {
                $processedItems[] = $this->processRawData(
                    $itemsClass,
                    FieldSource::Body,
                    InputBags::forItem($itemData, $context),
                );
            } catch (ValidationException $e) {
                foreach ($e->getErrors() as $field => $messages) {
                    $allErrors["{$fieldName}.{$index}.{$field}"] = $messages;
                }
            }
        }

        if (!empty($allErrors)) {
            throw new ValidationException($allErrors);
        }

        return $processedItems;
    }

    /**
     * Enrol a missing field into validation only when its rules can fire
     * without a value — literal 'required' or any conditional-presence
     * (required_*) rule.
     *
     * @param array<string, mixed>  $validationData
     * @param array<string, string> $validationRules
     */
    private function collectValidation(
        PropertyMetadata $property,
        mixed $value,
        array &$validationData,
        array &$validationRules,
    ): void {
        if (($property->isRequired || $property->validatesWhenAbsent) && $property->validationRules !== null) {
            $validationData[$property->name] = $value;
            $validationRules[$property->name] = $property->validationRules;
        }
    }

    private function castValue(mixed $value, PropertyMetadata $property): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($property->customCasterClass !== null) {
            return $this->processors->invokeCaster($property->customCasterClass, $value);
        }

        if ($property->effectiveCastType !== null) {
            return $this->builtInCaster->cast($property->effectiveCastType, $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @throws ValidationException
     */
    private function validate(array $data, array $rules): void
    {
        $errors = $this->validator->validate($data, $rules);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
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

        return $instance;
    }
}
