<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Casters;

/**
 * Interface for custom post-processors
 *
 * Implement this interface to create reusable post-processing logic
 *
 * Example:
 * ```php
 * final class SlugNormalizer implements PostProcessorInterface
 * {
 *     public function process(mixed $value): string
 *     {
 *         return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $value));
 *     }
 * }
 * ```
 */
interface PostProcessorInterface
{
    public function process(mixed $value): mixed;
}
