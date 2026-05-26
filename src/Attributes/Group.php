<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Assign the property to a named group for `Request::group()` extraction.
 *
 * `mapTo` optionally remaps the property name when the group output array is built.
 *
 * Example:
 * ```php
 * #[Validate('integer')]
 * #[Group('criteria', mapTo: 'positions.id')]
 * public int $position_id;
 *
 * #[Group('criteria')]
 * public ?string $search = null;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Group
{
    public function __construct(
        public string $name,
        public ?string $mapTo = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('#[Group] name must be a non-empty string.');
        }
        if ($mapTo !== null && $mapTo === '') {
            throw new InvalidArgumentException('#[Group] mapTo must be a non-empty string or null.');
        }
    }
}
