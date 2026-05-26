<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;

/**
 * Skip the property entirely. The handler will not read it from input,
 * validate it, cast it, or include it in `toArray()`. The property is also
 * invisible to `has()`, `get()`, and `group()`.
 *
 * Use for public properties that must remain untouched by the input pipeline:
 * controller-injected dependencies, computed values populated after
 * `handle*()`, or any state that the DTO exposes publicly but does not
 * receive from the request.
 *
 * `#[Ignore]` is the input-side counterpart of `#[Exclude]` (which still
 * runs the pipeline but hides the value from `toArray()` output).
 *
 * Combining `#[Ignore]` with any other RequestHandler attribute
 * (`#[Validate]`, `#[Cast]`, `#[FromRoute]`, `#[Generator]`, `#[Group]`, …)
 * throws `ConfigurationException::ignoreConflict` at metadata-build time —
 * an ignored property cannot also participate in the pipeline.
 *
 * Example:
 * ```php
 * #[Validate('required|string')]
 * public string $name;
 *
 * #[Ignore]
 * public ?User $currentUser = null;   // populated by controller, not from input
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Ignore
{
}
