<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;

/**
 * Exclude the property from `Request::toArray()` output.
 *
 * The property is still read from input, validated, cast and processed normally —
 * it just won't appear in the serialised array. Useful for sensitive fields
 * (passwords, tokens) that participate in the pipeline but must not leak out.
 *
 * `#[Exclude]` is the output-side counterpart of `#[Ignore]` (which acts on
 * the input pipeline).
 *
 * Example:
 * ```php
 * #[Validate('required|string|min:8')]
 * #[Exclude]
 * public string $password;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Exclude
{
}
