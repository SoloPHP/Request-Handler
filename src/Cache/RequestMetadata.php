<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionClass;

/**
 * Cached metadata for a request class
 *
 * @internal
 */
final readonly class RequestMetadata
{
    /**
     * @param class-string                      $className
     * @param array<string, PropertyMetadata>   $properties
     * @param ReflectionClass<covariant object> $reflection
     * @param list<string>                      $generated   Names of `#[Generator]` properties
     * @param list<string>                      $transformed Names of properties with a post-processor,
     *                                                       custom caster or `#[Items]`
     * @param array<string, list<string>>       $groups      `#[Group]` name → member property names
     */
    public function __construct(
        public string $className,
        public array $properties,
        public ReflectionClass $reflection,
        public array $generated = [],
        public array $transformed = [],
        public array $groups = [],
    ) {
    }
}
