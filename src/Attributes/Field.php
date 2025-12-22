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
        public ?string $group = null,
        public bool $uuid = false,
    ) {
    }
}
