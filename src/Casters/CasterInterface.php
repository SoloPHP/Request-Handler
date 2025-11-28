<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Casters;

/**
 * Interface for custom type casters
 *
 * Implement this interface to create custom casting logic for complex types
 *
 * Example:
 * ```php
 * final class MoneyCaster implements CasterInterface
 * {
 *     public function cast(mixed $value): Money
 *     {
 *         return new Money((int) round((float) $value * 100));
 *     }
 * }
 * ```
 */
interface CasterInterface
{
    public function cast(mixed $value): mixed;
}
