<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;

/**
 * Marks a property to be populated from HTTP request
 *
 * Example:
 * ```php
 * #[Field(rules: 'required|string')]
 * public string $name;
 *
 * #[Field(rules: 'integer', cast: 'int', mapFrom: 'user.id')]
 * public int $userId;
 *
 * #[Field(rules: 'nullable|string', group: 'criteria')]
 * public ?string $search = null;
 *
 * #[Field(rules: 'string|max:100', exclude: true)]
 * public string $internalField = 'default';
 *
 * #[Field(generator: UuidGenerator::class)]
 * public string $id;
 *
 * #[Field(generator: IntIdGenerator::class, generatorOptions: ['table' => 'users'])]
 * public int $id;
 *
 * #[Field(rules: 'nullable|array', items: OrderItemRequest::class)]
 * public ?array $items = null;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public ?string $rules = null,
        public ?string $cast = null,
        public ?string $mapFrom = null,
        public ?string $preProcess = null,
        public ?string $postProcess = null,
        /** @var array<string, mixed> */
        public array $postProcessConfig = [],
        public ?string $group = null,
        public ?string $generator = null,
        /** @var array<string, mixed> */
        public array $generatorOptions = [],
        public bool $exclude = false,
        /** @var class-string<\Solo\RequestHandler\Request>|null */
        public ?string $items = null,
    ) {
    }
}
