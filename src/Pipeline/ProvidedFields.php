<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Request;
use WeakMap;

/**
 * What the request supplied for each DTO `RequestHandler` built — kept
 * beside the instance rather than in it, so `(array)`, `serialize()` and
 * property normalizers see only the DTO's own fields. An entry lives as long
 * as its DTO.
 *
 * Each entry is a set of property names in declaration order, including
 * explicitly supplied nulls and generated values. A DTO supplied in full
 * shares its class's set rather than holding a copy, and DTOs of one class
 * supplied with the same fields — the items of a list — share one set too.
 *
 * @internal Read it through `Request::provided()`.
 */
final class ProvidedFields
{
    /** @var WeakMap<Request, array<string, true>>|null */
    private static ?WeakMap $fields = null;

    /** @var array<string, array<string, true>> The last partial set recorded per class. */
    private static array $partial = [];

    /**
     * @param array<string, mixed> $values What the handler assigned, keyed by property name.
     */
    public static function record(Request $dto, RequestMetadata $metadata, array $values): void
    {
        if (count($values) === count($metadata->names)) {
            $provided = $metadata->names;
        } else {
            $provided = self::$partial[$metadata->className] ?? [];
            if (count($provided) !== count($values) || array_diff_key($values, $provided) !== []) {
                // Declaration order, whatever the order of $values: finalize()
                // adds generated values last.
                $provided = self::$partial[$metadata->className] = array_intersect_key($metadata->names, $values);
            }
        }
        self::$fields ??= new WeakMap();
        self::$fields[$dto] = $provided;
    }

    /**
     * The names the request supplied, in declaration order.
     *
     * @return array<string, true>
     */
    public static function of(Request $dto): array
    {
        return self::$fields[$dto] ?? [];
    }

    /**
     * Whether the request supplied the property.
     */
    public static function has(Request $dto, string $name): bool
    {
        return isset(self::$fields[$dto][$name]);
    }

    /**
     * Whether `RequestHandler` built the DTO — and so recorded what the
     * request supplied for it, possibly nothing.
     */
    public static function knows(Request $dto): bool
    {
        return isset(self::$fields[$dto]);
    }
}
