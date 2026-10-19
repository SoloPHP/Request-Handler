<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Explicit scalar cast for the property value: `int`, `float`, `bool`,
 * `string` or `array`.
 *
 * Without this attribute, casting is driven by the property's declared type
 * (e.g. `public int $age` is cast as int), so `#[Cast]` is only needed where
 * the type does not say it — a union or `mixed` property.
 *
 * Dates are cast by their type too; use `#[DateFormat]` for a fixed format.
 * For custom logic use `#[Caster(MyCaster::class)]` — `#[Cast]` does NOT
 * accept class names and throws `ConfigurationException` at metadata build
 * time if given one.
 *
 * Example:
 * ```php
 * #[Cast('float')]
 * public int|float $amount;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(
        public string $type,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('#[Cast] type must be a non-empty string.');
        }
    }
}
