<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Grouping;

use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Request;

/**
 * Assembles a group from current DTO values using cached metadata.
 *
 * @internal Use `Request::group()`.
 */
final class GroupBuilder
{
    /** Duplicate logical connectors coexist as separate list entries. */
    private const array GROUP_KEYS = ['AND', 'OR'];

    /**
     * @return array<array-key, mixed>
     * @throws \LogicException When duplicate non-connector keys are detected.
     */
    public static function build(Request $request, RequestMetadata $metadata, string $groupName): array
    {
        $result = [];

        foreach ($metadata->groups[$groupName] ?? [] as $name) {
            $property = $metadata->properties[$name];
            if (!$property->reflection->isInitialized($request)) {
                continue;
            }

            $value = $property->reflection->getValue($request);

            // Skip empty arrays and nulls — they convey "no criteria for this slot".
            if ($value === [] || $value === null) {
                continue;
            }

            $entries = (is_array($value) && !array_is_list($value))
                ? $value
                : [($property->groupMapTo ?? $name) => $value];

            foreach ($entries as $key => $v) {
                if (array_key_exists($key, $result)) {
                    if (in_array($key, self::GROUP_KEYS, true)) {
                        $result[] = [$key => $v];
                        continue;
                    }
                    throw new \LogicException(
                        "Duplicate key '$key' in group '$groupName' from property '{$name}'"
                    );
                }
                $result[$key] = $v;
            }
        }

        return $result;
    }
}
