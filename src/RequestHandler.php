<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Cache\ProcessorKind;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;

/**
 * Factory for creating request DTOs from HTTP requests
 *
 * Example usage:
 * ```php
 * $data = $requestHandler->handleBody(ProductRequest::class, $request);
 * // $data is now a ProductRequest instance with only present fields
 * ```
 */
final class RequestHandler
{
    private ReflectionCache $cache;
    private BuiltInCaster $builtInCaster;

    /** @var array<class-string, ProcessorInterface|CasterInterface|GeneratorInterface|object> */
    private array $processors = [];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly bool $autoTrim = true
    ) {
        $this->cache = new ReflectionCache();
        $this->builtInCaster = new BuiltInCaster();
    }

    /**
     * Register a processor/caster/generator instance for dependency injection
     *
     * Use this method when your processor/caster/generator requires constructor dependencies.
     *
     * Example:
     * ```php
     * $handler->register(SequenceGenerator::class, new SequenceGenerator($connection));
     * $handler->register(SlugProcessor::class, new SlugProcessor($transliterator));
     * ```
     *
     * @param class-string $className
     */
    public function register(string $className, object $instance): self
    {
        $this->processors[$className] = $instance;
        return $this;
    }

    /**
     * Create a Request DTO from query parameters (GET requests)
     *
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $routeParams
     * @return T
     */
    public function handleQuery(string $className, ServerRequestInterface $request, array $routeParams = []): Request
    {
        return $this->processRawData($className, $request->getQueryParams(), $routeParams);
    }

    /**
     * Create a Request DTO from request body (POST/PUT/PATCH requests)
     *
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $routeParams
     * @return T
     */
    public function handleBody(string $className, ServerRequestInterface $request, array $routeParams = []): Request
    {
        $body = $request->getParsedBody();
        return $this->processRawData($className, is_array($body) ? $body : [], $routeParams);
    }

    /**
     * Create a Request DTO from a raw array (useful for nested items processing)
     *
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $data
     * @return T
     */
    public function handleArray(string $className, array $data): Request
    {
        return $this->processRawData($className, $data, []);
    }

    /**
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $rawData
     * @param array<string, mixed> $routeParams
     * @return T
     */
    private function processRawData(string $className, array $rawData, array $routeParams): Request
    {
        $metadata = $this->cache->get($className);
        /** @var T $instance */
        $instance = $metadata->reflection->newInstanceWithoutConstructor();

        /** @var array<string, mixed> $validationData */
        $validationData = [];
        /** @var array<string, string> $validationRules */
        $validationRules = [];
        /** @var array<string, mixed> $presentFields */
        $presentFields = [];

        foreach ($metadata->properties as $property) {
            if ($property->generator !== null) {
                /** @var class-string<GeneratorInterface> $generatorClass */
                $generatorClass = $property->generator;
                $presentFields[$property->name] = $this->runGenerator($generatorClass, $property->generatorOptions);
                continue;
            }

            $hasValueInRequest = false;
            $value = $this->resolveInputValue($rawData, $property->inputPath, $hasValueInRequest);

            if ($this->autoTrim && is_string($value)) {
                $value = trim($value);
            }

            if (!$hasValueInRequest) {
                if ($property->hasDefault) {
                    continue;
                }
                $this->collectValidation($property, null, $validationData, $validationRules);
                continue;
            }

            if ($value === null) {
                $presentFields[$property->name] = $property->isNullable
                    ? null
                    : ($property->hasDefault ? $property->defaultValue : null);
                $this->collectValidation($property, null, $validationData, $validationRules);
                continue;
            }

            if ($value === '') {
                $presentFields[$property->name] = '';
                $this->collectValidation($property, '', $validationData, $validationRules);
                continue;
            }

            if ($property->preProcessorKind !== null) {
                /** @var string $handler */
                $handler = $property->preProcessor;
                $value = $this->runProcessor(
                    $handler,
                    $property->preProcessorKind,
                    $value,
                    $className,
                    [],
                    $routeParams
                );
            }

            if ($property->validationRules !== null) {
                $validationData[$property->name] = $value;
                $validationRules[$property->name] = $property->validationRules;
            }

            $presentFields[$property->name] = $value;
        }

        if (!empty($validationRules)) {
            $validationRules = $this->replaceRulePlaceholders($validationRules, $routeParams);
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
                $value = $this->runProcessor(
                    $handler,
                    $property->postProcessorKind,
                    $value,
                    $className,
                    $property->postProcessConfig,
                    $routeParams
                );
            }

            if ($property->items !== null && is_array($value)) {
                $value = $this->processItems($property->items, $value, $name, $routeParams);
            }

            $presentFields[$name] = $value;
        }

        return $this->populateInstance($instance, $metadata, $presentFields);
    }

    /**
     * Process array items through a Request class.
     *
     * @param class-string<Request> $itemsClass
     * @param array<int|string, mixed> $items
     * @param array<string, mixed> $routeParams
     * @return array<int, Request>
     * @throws ValidationException
     */
    private function processItems(string $itemsClass, array $items, string $fieldName, array $routeParams): array
    {
        $allErrors = [];
        $processedItems = [];

        foreach ($items as $index => $itemData) {
            if (!is_array($itemData)) {
                $allErrors["{$fieldName}.{$index}"] = [['rule' => 'array']];
                continue;
            }

            try {
                $processedItems[] = $this->processRawData($itemsClass, $itemData, $routeParams);
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
     * Add a field to the validation set when its rules dictate validation should run.
     *
     * @param array<string, mixed>  $validationData
     * @param array<string, string> $validationRules
     */
    private function collectValidation(
        PropertyMetadata $property,
        mixed $value,
        array &$validationData,
        array &$validationRules
    ): void {
        if ($property->isRequired && $property->validationRules !== null) {
            $validationData[$property->name] = $value;
            $validationRules[$property->name] = $property->validationRules;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string>        $path
     */
    private function resolveInputValue(array $data, array $path, bool &$hasValue): mixed
    {
        $current = $data;
        $hasValue = true;

        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                $hasValue = false;
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param class-string $className
     * @param array<string, mixed> $config
     * @param array<string, mixed> $routeParams
     */
    private function runProcessor(
        string $handler,
        ProcessorKind $kind,
        mixed $value,
        string $className,
        array $config,
        array $routeParams
    ): mixed {
        return match ($kind) {
            ProcessorKind::Func => $handler($value), // @phpstan-ignore-line callable.nonCallable
            ProcessorKind::ProcessorInterface => $this->invokeProcessor($handler, $value, $config, $routeParams),
            ProcessorKind::CasterInterface => $this->invokeCaster($handler, $value),
            ProcessorKind::StaticMethod => $className::$handler($value),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $routeParams
     */
    private function invokeProcessor(string $handler, mixed $value, array $config, array $routeParams): mixed
    {
        /** @var ProcessorInterface $processor */
        $processor = $this->getOrCreateProcessor($handler);
        return $processor->process($value, new ProcessContext($config, $routeParams));
    }

    private function invokeCaster(string $handler, mixed $value): mixed
    {
        /** @var CasterInterface $caster */
        $caster = $this->getOrCreateProcessor($handler);
        return $caster->cast($value);
    }

    /**
     * Look up or instantiate a processor/caster/generator. The class is validated at metadata build time
     * via ReflectionCache::classifyProcessor/validateGenerator/resolveCast, so callers can trust the string
     * is a real class name even though PHPStan can't narrow it here.
     */
    private function getOrCreateProcessor(string $className): object
    {
        /** @var class-string $className */
        return $this->processors[$className] ??= new $className();
    }

    private function castValue(mixed $value, PropertyMetadata $property): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($property->customCasterClass !== null) {
            return $this->invokeCaster($property->customCasterClass, $value);
        }

        if ($property->effectiveCastType !== null) {
            return $this->builtInCaster->cast($property->effectiveCastType, $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
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
     * Replace {key} placeholders in rules with values from route params.
     *
     * @param array<string, string> $rules
     * @param array<string, mixed>  $routeParams
     * @return array<string, string>
     */
    private function replaceRulePlaceholders(array $rules, array $routeParams): array
    {
        if (empty($routeParams)) {
            return $rules;
        }

        $replacements = [];
        foreach ($routeParams as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        foreach ($rules as $field => $rule) {
            $rules[$field] = strtr($rule, $replacements);
        }

        return $rules;
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

    /**
     * @param class-string<GeneratorInterface> $generatorClass
     * @param array<string, mixed> $options
     */
    private function runGenerator(string $generatorClass, array $options): mixed
    {
        /** @var GeneratorInterface $generator */
        $generator = $this->getOrCreateProcessor($generatorClass);
        return $generator->generate($options);
    }
}
