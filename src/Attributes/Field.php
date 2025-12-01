<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;

/**
 * Defines a field for request DTO with validation and casting rules
 *
 * Example:
 * ```php
 * #[AsRequest]
 * #[Field('name', 'required|string|max:255')]
 * #[Field('price', 'required|numeric', cast: 'float')]
 * #[Field('description', 'nullable|string')]
 * #[Field('status', 'string|in:draft,active', default: 'draft')]
 * final class ProductRequest
 * {
 *     use DynamicProperties;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Field
{
    public function __construct(
        public string $name,
        public ?string $rules = null,
        public ?string $cast = null,
        public ?string $mapFrom = null,
        public mixed $default = new NotSet(),
        public ?string $preProcess = null,
        public ?string $postProcess = null,
        public ?string $group = null,
    ) {
    }

    public function hasDefault(): bool
    {
        return !$this->default instanceof NotSet;
    }
}
