<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

/**
 * Interface for custom processors (preProcess and postProcess)
 *
 * Implement this interface to create reusable processing logic
 *
 * Example:
 * ```php
 * final class SlugNormalizer implements ProcessorInterface
 * {
 *     public function process(mixed $value): string
 *     {
 *         return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $value));
 *     }
 * }
 * ```
 */
interface ProcessorInterface
{
    public function process(mixed $value): mixed;
}
