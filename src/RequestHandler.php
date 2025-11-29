<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Casters\BuiltInCaster;
use Solo\RequestHandler\Casters\CasterInterface;
use Solo\RequestHandler\Casters\PostProcessorInterface;
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

    public function __construct(
        private readonly ValidatorInterface $validator
    ) {
        $this->cache = new ReflectionCache();
        $this->builtInCaster = new BuiltInCaster();
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     * @throws ValidationException
     */
    public function handle(string $className, ServerRequestInterface $request): object
    {
        $metadata = $this->cache->get($className);

        // Extract raw data from request
        $rawData = $this->extractData($request);

        // Process fields
        $validationData = [];
        $validationRules = [];
        $presentFields = [];

        foreach ($metadata->properties as $property) {
            $hasValueInRequest = $this->hasValue($rawData, $property->inputName);
            $value = $this->getValue($rawData, $property);
            $isEmpty = $value === null || $value === '';

            // If field is empty and not in request, skip
            if ($isEmpty && !$hasValueInRequest) {
                if ($property->hasDefault) {
                    $presentFields[$property->name] = $property->defaultValue;
                }
                continue;
            }

            // If field is empty but was in request, include it (user wants to clear)
            // Unless it has a default value
            if ($isEmpty && $hasValueInRequest) {
                if ($property->hasDefault) {
                    $presentFields[$property->name] = $property->defaultValue;
                } else {
                    $presentFields[$property->name] = null;
                }
                // Only validate required fields (not nullable)
                if ($property->validationRules !== null && !str_contains($property->validationRules, 'nullable')) {
                    $validationData[$property->name] = $value;
                    $validationRules[$property->name] = $property->validationRules;
                }
                continue;
            }

            // Pre-process
            if ($property->preProcessor !== null) {
                $value = $this->runProcessor($property->preProcessor, $value, $className);
            }

            // Store for validation
            $validationData[$property->name] = $value;
            if ($property->validationRules !== null) {
                $validationRules[$property->name] = $property->validationRules;
            }

            $presentFields[$property->name] = $value;
        }

        // Validate
        if (!empty($validationRules)) {
            $this->validate($validationData, $validationRules);
        }

        // Cast and post-process
        foreach ($presentFields as $name => $value) {
            $property = $metadata->properties[$name];

            // Cast
            $value = $this->castValue($value, $property);

            // Post-process
            if ($property->postProcessor !== null) {
                $value = $this->runProcessor($property->postProcessor, $value, $className);
            }

            $presentFields[$name] = $value;
        }

        // Create instance with dynamic properties
        return $this->createInstance($className, $presentFields);
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
    private function getValue(array $data, PropertyMetadata $property): mixed
    {
        return $this->getNestedValue($data, $property->inputName);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasValue(array $data, string $path): bool
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
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

        // Check if it's a class implementing PostProcessorInterface or CasterInterface
        if (class_exists($handler)) {
            $processor = new $handler();
            if ($processor instanceof PostProcessorInterface) {
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

        return $value;
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
                $caster = new $property->castType();
                if ($caster instanceof CasterInterface) {
                    return $caster->cast($value);
                }
            }
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
     * @template T of object
     * @param class-string<T> $className
     * @param array<string, mixed> $data
     * @return T
     */
    private function createInstance(string $className, array $data): object
    {
        $reflection = new ReflectionClass($className);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set properties via __set magic method (from DynamicProperties trait)
        foreach ($data as $name => $value) {
            $instance->$name = $value;
        }

        return $instance;
    }
}
