<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

use ReflectionProperty;
use Solo\RequestHandler\FieldSource;
use Solo\RequestHandler\Request;

/**
 * Cached metadata for a single field.
 *
 * `source` is null when the property uses the default source dictated by the
 * `handle*` method (Body for `handleBody`/`handleArray`, Query for `handleQuery`).
 * `sourceKey` is always populated.
 *
 * `validatesWhenAbsent` marks a field whose rule chain can produce an error
 * even when the key is missing from the payload — the literal `required` rule
 * and the whole conditional-presence family (`required_if`, `required_with`,
 * `required_without_all`, ...). `isRequired` stays literal-`required` only,
 * because it also drives the "required field cannot have a default" check and
 * the exported schema.
 *
 * `pathSegments` is non-null only when the property is sourced via
 * `#[FromBody('a.b.c')]` — in that case `InputBags::lookup` walks the body
 * bag segment by segment instead of doing a flat array_key_exists lookup.
 */
final readonly class PropertyMetadata
{
    /**
     * @param array<string, mixed>       $postProcessConfig
     * @param array<string, mixed>       $generatorOptions
     * @param class-string<Request>|null $items
     * @param list<string>|null          $pathSegments
     */
    public function __construct(
        public string $name,
        public ?FieldSource $source,
        public string $sourceKey,
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
        public bool $validatesWhenAbsent,
        public ReflectionProperty $reflection,
        public ?string $generator = null,
        public array $generatorOptions = [],
        public bool $exclude = false,
        public ?string $items = null,
        public ?array $pathSegments = null,
    ) {
    }
}
