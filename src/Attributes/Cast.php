<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Explicit built-in cast for the property value.
 *
 * Supported types: `int`, `float`, `bool`, `string`, `array`,
 * `datetime`, `datetime:immutable`, `datetime:Y-m-d`, `datetime:immutable:Y-m-d H:i:s`.
 *
 * For custom caster classes use `#[Caster(MyCaster::class)]` — `#[Cast]` does
 * NOT accept class names and will throw `ConfigurationException` at metadata
 * build time if given one.
 *
 * Without this attribute, casting is driven by the property's declared type
 * (e.g. `public int $age` is cast as int).
 *
 * Example:
 * ```php
 * #[Cast('datetime:immutable:Y-m-d')]
 * public DateTimeImmutable $birthday;
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
