<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Run a handler on the value BEFORE validation.
 *
 * Handler can be:
 * - a global function name (`'trim'`, `'strtolower'`)
 * - a class implementing `ProcessorInterface` or `CasterInterface`
 * - a static method on the Request class itself (must be `public static`)
 *
 * Example:
 * ```php
 * #[PreProcess(PhoneNormalizer::class)]
 * public string $phone;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PreProcess
{
    public function __construct(
        public string $handler,
    ) {
        if ($handler === '') {
            throw new InvalidArgumentException('#[PreProcess] handler must be a non-empty string.');
        }
    }
}
