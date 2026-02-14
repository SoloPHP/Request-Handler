<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

/**
 * Cached metadata for a single field
 */
final readonly class PropertyMetadata
{
    /**
     * @param array<string, mixed> $generatorOptions
     */
    public function __construct(
        public string $name,
        public string $inputName,
        public ?string $type,
        public bool $isNullable,
        public bool $hasDefault,
        public mixed $defaultValue,
        public ?string $validationRules,
        public ?string $castType,
        public ?string $preProcessor,
        public ?string $postProcessor,
        public ?string $group,
        public bool $isRequired,
        public ?string $generator = null,
        public array $generatorOptions = [],
        public bool $exclude = false,
        /** @var class-string<\Solo\RequestHandler\Request>|null */
        public ?string $items = null,
    ) {
    }
}
