<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use Solo\RequestHandler\Request;

/**
 * Marks the property as an array of nested Request DTOs.
 *
 * Each element in the input array is processed through the given Request class.
 * The property must be typed `array` or `?array`.
 *
 * Mutually exclusive with `#[Cast]` and `#[Caster]` (items bypass automatic
 * casting — each element runs through its own DTO pipeline).
 *
 * Example:
 * ```php
 * #[Validate('nullable|array')]
 * #[Items(OrderItemRequest::class)]
 * public ?array $items = null;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Items
{
    /**
     * @param class-string<Request> $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
