<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;
use ReflectionClass;

/**
 * Factory for creating request DTOs from HTTP requests
 *
 * Example usage:
 * ```php
 * $data = $requestHandler->handle(ProductRequest::class, $request);
 * // $data is now a ProductRequest instance with only present fields
 * ```
 */
final class RequestHandler
{
    private ReflectionCache $cache;
    private BuiltInCaster $builtInCaster;

    /** @var array<class-string, ProcessorInterface|CasterInterface|object> */
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
     * Create a Request DTO from an HTTP request
     *
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $routeParams
     * @return T
     */
    public function handle(string $className, ServerRequestInterface $request, array $routeParams = []): Request
    {
        $rawData = $this->extractData($request);
        return $this->processRawData($className, $rawData, $routeParams);
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
        return $this->processRawData($className, $data);
    }

    /**
     * Core processing: validate, cast, post-process, and populate a Request DTO
     *
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $rawData
     * @param array<string, mixed> $routeParams
     * @return T
     */
    private function processRawData(string $className, array $rawData, array $routeParams = []): Request
    {
        $metadata = $this->cache->get($className);

        // Create instance early
        $reflection = new ReflectionClass($className);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Process fields
        /** @var array<string, mixed> $validationData */
        $validationData = [];
        /** @var array<string, string> $validationRules */
        $validationRules = [];
        /** @var array<string, mixed> $presentFields */
        $presentFields = [];

        foreach ($metadata->properties as $property) {
            // Generate value if field has generator
            if ($property->generator !== null) {
                /** @var class-string<GeneratorInterface> $generatorClass */
                $generatorClass = $property->generator;
                $presentFields[$property->name] = $this->runGenerator(
                    $generatorClass,
                    $property->generatorOptions
                );
                continue;
            }

            $hasValueInRequest = false;
            $value = $this->getValue($rawData, $property->inputName, $hasValueInRequest);

            // Auto-trim strings
            if ($this->autoTrim && is_string($value)) {
                $value = trim($value);
            }

            $isEmpty = $value === null || $value === '';

            // If field is empty and not in request
            if ($isEmpty && !$hasValueInRequest) {
                // Use default if available
                if ($property->hasDefault) {
                    // Property already has default value from class definition
                    continue;
                } elseif ($property->isRequired && $property->validationRules !== null) {
                    // Required field missing - add to validation to trigger error
                    $validationData[$property->name] = null;
                    $validationRules[$property->name] = $property->validationRules;
                }
                continue;
            }

            // If field is empty but was in request (user wants to clear)
            if ($isEmpty) {
                if ($property->hasDefault && $property->defaultValue !== null) {
                    $presentFields[$property->name] = $property->defaultValue;
                } else {
                    $presentFields[$property->name] = null;
                }
                // Validate required fields
                if ($property->isRequired && $property->validationRules !== null) {
                    $validationData[$property->name] = $value;
                    $validationRules[$property->name] = $property->validationRules;
                }
                continue;
            }

            // Pre-process
            if ($property->preProcessor !== null) {
                $value = $this->runProcessor($property->preProcessor, $value, $className);
            }

            // Store for validation (only if there are rules)
            if ($property->validationRules !== null) {
                $validationData[$property->name] = $value;
                $validationRules[$property->name] = $property->validationRules;
            }

            $presentFields[$property->name] = $value;
        }

        // Validate
        if (!empty($validationRules)) {
            $validationRules = $this->replaceRulePlaceholders($validationRules, $routeParams);
            $this->validate($validationData, $validationRules);
        }

        // Cast, post-process, and process items
        foreach ($presentFields as $name => $value) {
            $property = $metadata->properties[$name];

            // Cast (skip when postProcessor or items handles transformation)
            if ($property->postProcessor === null && $property->items === null) {
                $value = $this->castValue($value, $property);
            }

            // Post-process
            if ($property->postProcessor !== null) {
                $value = $this->runProcessor(
                    $property->postProcessor,
                    $value,
                    $className,
                    $property->postProcessConfig
                );
            }

            // Process array items through referenced Request class
            if ($property->items !== null && is_array($value)) {
                $value = $this->processItems($property->items, $value, $name, $routeParams);
            }

            $presentFields[$name] = $value;
        }

        // Set property values on instance
        return $this->populateInstance($instance, $reflection, $presentFields);
    }

    /**
     * Process array items through a Request class
     *
     * @param class-string<Request> $itemsClass
     * @param array<int|string, mixed> $items
     * @param string $fieldName Parent field name for error prefixing
     * @param array<string, mixed> $routeParams
     * @return array<int, array<string, mixed>> Processed items as arrays
     * @throws ValidationException
     */
    private function processItems(string $itemsClass, array $items, string $fieldName, array $routeParams = []): array
    {
        $allErrors = [];
        $processedItems = [];

        foreach ($items as $index => $itemData) {
            if (!is_array($itemData)) {
                $allErrors["{$fieldName}.{$index}"] = [['rule' => 'array']];
                continue;
            }

            try {
                $instance = $this->processRawData($itemsClass, $itemData, $routeParams);
                $processedItems[] = $instance->toArray();
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
     * @return array<string, mixed>
     */
    private function extractData(ServerRequestInterface $request): array
    {
        $method = $request->getMethod();

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $query = $request->getQueryParams();
            return array_merge($query, is_array($body) ? $body : []);
        }

        return $request->getQueryParams();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getValue(array $data, string $path, bool &$hasValue): mixed
    {
        $keys = explode('.', $path);
        $current = $data;
        $hasValue = true;

        foreach ($keys as $key) {
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
     */
    private function runProcessor(string $handler, mixed $value, string $className, array $config = []): mixed
    {
        // Check if it's a global function
        if (function_exists($handler)) {
            return $handler($value);
        }

        // Check if it's a class implementing ProcessorInterface or CasterInterface
        if (class_exists($handler)) {
            $processor = $this->getOrCreateProcessor($handler);

            if ($processor instanceof ProcessorInterface) {
                return !empty($config)
                    ? $processor->process($value, $config) // @phpstan-ignore arguments.count
                    : $processor->process($value);
            }
            if ($processor instanceof CasterInterface) {
                return $processor->cast($value);
            }
        }

        // Check if class has static method
        if (method_exists($className, $handler)) {
            return $className::$handler($value);
        }

        // This should never be reached if ReflectionCache validation is working correctly
        // @codeCoverageIgnoreStart
        return $value;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get or create a cached processor/caster instance
     *
     * @param class-string $className
     */
    private function getOrCreateProcessor(string $className): object
    {
        if (!isset($this->processors[$className])) {
            $this->processors[$className] = new $className();
        }

        return $this->processors[$className];
    }

    private function castValue(mixed $value, PropertyMetadata $property): mixed
    {
        if ($value === null) {
            return null;
        }

        // Explicit cast attribute takes priority
        if ($property->castType !== null) {
            if ($this->builtInCaster->isBuiltIn($property->castType)) {
                return $this->builtInCaster->cast($property->castType, $value);
            }

            // Custom caster class
            if (class_exists($property->castType)) {
                $caster = $this->getOrCreateProcessor($property->castType);

                if ($caster instanceof CasterInterface) {
                    return $caster->cast($value);
                }
            }
        }

        // If no explicit cast, use property type for automatic casting
        if ($property->type !== null && $this->builtInCaster->isBuiltIn($property->type)) {
            return $this->builtInCaster->cast($property->type, $value);
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
     * Replace {key} placeholders in rules with values from route params
     *
     * @param array<string, string> $rules
     * @param array<string, mixed> $routeParams
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
     * @param ReflectionClass<T> $reflection
     * @param array<string, mixed> $data
     * @return T
     */
    private function populateInstance(Request $instance, ReflectionClass $reflection, array $data): Request
    {
        foreach ($data as $name => $value) {
            try {
                $property = $reflection->getProperty($name);
                if ($property->isPublic() && !$property->isStatic()) {
                    // Runtime protection: prevent null assignment to non-nullable types
                    if ($value === null) {
                        $type = $property->getType();
                        if ($type && !$type->allowsNull()) {
                            continue;
                        }
                    }

                    $property->setValue($instance, $value);
                }
            } catch (\ReflectionException) {
                // Property doesn't exist - ignore
            }
        }

        return $instance;
    }

    /**
     * Run generator and return generated value
     *
     * @param class-string<GeneratorInterface> $generatorClass
     * @param array<string, mixed> $options
     */
    private function runGenerator(string $generatorClass, array $options): mixed
    {
        $generator = $this->getOrCreateProcessor($generatorClass);

        if ($generator instanceof GeneratorInterface) {
            return $generator->generate($options);
        }

        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }
}
