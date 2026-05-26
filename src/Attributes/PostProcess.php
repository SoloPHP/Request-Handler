<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Run a handler on the value AFTER validation.
 *
 * Handler can be:
 * - a global function name
 * - a class implementing `ProcessorInterface` or `CasterInterface`
 * - a `public static` method on the Request class itself
 *
 * `config` is passed to `ProcessorInterface::process()` via `ProcessContext::$config`.
 * It is ignored for function/static-method/CasterInterface handlers.
 *
 * Example:
 * ```php
 * #[PostProcess(SlugProcessor::class, config: ['separator' => '-'])]
 * public string $slug;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PostProcess
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $handler,
        public array $config = [],
    ) {
        if ($handler === '') {
            throw new InvalidArgumentException('#[PostProcess] handler must be a non-empty string.');
        }
    }
}
