<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;

/**
 * Cached metadata for a request class
 */
final readonly class RequestMetadata
{
    /**
     * @param class-string                    $className
     * @param array<string, PropertyMetadata> $properties
     * @param ReflectionClass<object>         $reflection
     */
    public function __construct(
        public string $className,
        public array $properties,
        public ReflectionClass $reflection,
    ) {
    }
}
