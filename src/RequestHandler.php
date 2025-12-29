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
     * @template T of Request
     * @param class-string<T> $className
     * @param array<string, mixed> $routeParams
     * @return T
     */
    public function handle(string $className, ServerRequestInterface $request, array $routeParams = []): Request
    {
        $metadata = $this->cache->get($className);

        // Create instance early to get custom messages
        $reflection = new ReflectionClass($className);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Extract raw data from request
        $rawData = $this->extractData($request);

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
            $this->validate($validationData, $validationRules, $instance->getMessages());
        }

        // Cast and post-process
        foreach ($presentFields as $name => $value) {
            $property = $metadata->properties[$name];

            // Cast (skip when postProcessor is defined, as it handles transformation)
            if ($property->postProcessor === null) {
                $value = $this->castValue($value, $property);
            }

            // Post-process
            if ($property->postProcessor !== null) {
                $value = $this->runProcessor($property->postProcessor, $value, $className);
            }

            $presentFields[$name] = $value;
        }

        // Set property values on instance
        return $this->populateInstance($instance, $reflection, $presentFields);
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
     */
    private function runProcessor(string $handler, mixed $value, string $className): mixed
    {
        // Check if it's a global function
        if (function_exists($handler)) {
            return $handler($value);
        }

        // Check if it's a class implementing ProcessorInterface or CasterInterface
        if (class_exists($handler)) {
            $processor = $this->getOrCreateProcessor($handler);

            if ($processor instanceof ProcessorInterface) {
                return $processor->process($value);
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
     * @param array<string, string> $messages
     * @throws ValidationException
     */
    private function validate(array $data, array $rules, array $messages = []): void
    {
        $errors = $this->validator->validate($data, $rules, $messages);

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
