<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Helpers;

use Solo\RequestHandler\Contracts\ParameterParserInterface;

/**
 * Helper class for parsing request parameters into repository-compatible formats
 */
final readonly class ParameterParser implements ParameterParserInterface
{
    /**
     * Parse sort parameter from string format (?sort=name or ?sort=-name)
     *
     * @param string|null $sort Sort parameter (e.g., "name" or "-created_at")
     * @return array<string, string>|null Parsed sort array ['field' => 'ASC/DESC'] or null
     */
    public static function sort(?string $sort): ?array
    {
        if (empty($sort)) {
            return null;
        }

        $orderBy = [];

        if (str_starts_with($sort, '-')) {
            $field = substr($sort, 1);
            $direction = 'DESC';
        } else {
            $field = $sort;
            $direction = 'ASC';
        }

        $orderBy[$field] = $direction;

        return $orderBy;
    }

    /**
     * Parse search parameter for repository filtering
     *
     * @param mixed $search Search parameter
     * @return array<mixed> Parsed search array or empty array
     */
    public static function search(mixed $search): array
    {
        return !empty($search) ? (array) $search : [];
    }

    /**
     * Parse filter parameter for repository filtering
     *
     * @param mixed $filter Filter parameter
     * @return array<string, mixed> Parsed filter array wrapped in 'filter' key or empty array
     */
    public static function filter(mixed $filter): array
    {
        return !empty($filter) ? ['filter' => (array) $filter] : [];
    }

    /**
     * Parse boolean parameter to MySQL-compatible integer (0 or 1)
     *
     * @param mixed $value Boolean-like value to parse
     * @return int 0 for false-like values, 1 for true-like values
     */
    public static function boolean(mixed $value): int
    {
        if (is_bool($value)) {
            return (int)$value;
        }

        if (is_string($value)) {
            $lower = strtolower($value);
            return match ($lower) {
                'true', '1', 'yes', 'on' => 1,
                'false', '0', 'no', 'off' => 0,
                default => (int)(bool)$value
            };
        }

        return (int)(bool)$value;
    }
}
