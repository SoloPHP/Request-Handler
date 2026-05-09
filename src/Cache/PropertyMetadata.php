<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionProperty;
use Solo\RequestHandler\Request;

/**
 * Cached metadata for a single field
 */
final readonly class PropertyMetadata
{
    /**
     * @param array<string>              $inputPath         Pre-exploded `mapFrom` path.
     * @param array<string, mixed>       $postProcessConfig
     * @param array<string, mixed>       $generatorOptions
     * @param class-string<Request>|null $items
     */
    public function __construct(
        public string $name,
        public string $inputName,
        public array $inputPath,
        public ?string $type,
        public bool $isNullable,
        public bool $hasDefault,
        public mixed $defaultValue,
        public ?string $validationRules,
        public ?string $castType,
        public ?string $effectiveCastType,
        public ?string $customCasterClass,
        public ?string $preProcessor,
        public ?ProcessorKind $preProcessorKind,
        public ?string $postProcessor,
        public ?ProcessorKind $postProcessorKind,
        public array $postProcessConfig,
        public ?string $group,
        public bool $isRequired,
        public ReflectionProperty $reflection,
        public ?string $generator = null,
        public array $generatorOptions = [],
        public bool $exclude = false,
        public ?string $items = null,
    ) {
    }
}
