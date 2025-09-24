<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

/**
 * Interface for parsing request parameters into repository-compatible formats
 */
interface ParameterParserInterface
{
    /**
     * Parse sort parameter from string format (?sort=name or ?sort=-name)
     *
     * @param string|null $sort Sort parameter (e.g., "name" or "-created_at")
     * @return array<string, string>|null Parsed sort array ['field' => 'ASC/DESC'] or null
     */
    public static function sort(?string $sort): ?array;

    /**
     * Parse search parameter for repository filtering
     *
     * @param mixed $search Search parameter
     * @return array<mixed> Parsed search array or empty array
     */
    public static function search(mixed $search): array;

    /**
     * Parse filter parameter for repository filtering
     *
     * @param mixed $filter Filter parameter
     * @return array<string, mixed> Parsed filter array wrapped in 'filter' key or empty array
     */
    public static function filter(mixed $filter): array;

    /**
     * Parse boolean parameter to MySQL-compatible integer (0 or 1)
     *
     * @param mixed $value Boolean-like value to parse
     * @return int 0 for false-like values, 1 for true-like values
     */
    public static function boolean(mixed $value): int;
}
