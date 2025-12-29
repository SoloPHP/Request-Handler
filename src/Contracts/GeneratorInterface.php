<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

/**
 * Interface for field value generators
 *
 * Implement this interface to create custom value generation logic
 *
 * Example:
 * ```php
 * final class UuidGenerator implements GeneratorInterface
 * {
 *     public function generate(array $options = []): string
 *     {
 *         return Uuid::uuid4()->toString();
 *     }
 * }
 * ```
 */
interface GeneratorInterface
{
    /**
     * Generate a value for a field.
     *
     * @param array<string, mixed> $options Options passed from generatorOptions attribute
     * @return mixed The generated value
     */
    public function generate(array $options = []): mixed;
}
