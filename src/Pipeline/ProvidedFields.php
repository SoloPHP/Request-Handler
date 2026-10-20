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
 * An entry is an int with a bit per property (`RequestMetadata::$bits`), so
 * it takes no memory beyond its slot in the table. A class with more
 * properties than an int has bits keeps the names instead.
 *
 * @internal Read it through `Request::provided()`.
 */
final class ProvidedFields
{
    /** @var WeakMap<Request, int|array<string, true>>|null */
    private static ?WeakMap $fields = null;

    /**
     * @param array<string, mixed> $values What the handler assigned, keyed by property name.
     */
    public static function record(Request $dto, RequestMetadata $metadata, array $values): void
    {
        $bits = $metadata->bits;
        if ($bits === null) {
            // Declaration order: hydrate() adds generated values last.
            $provided = array_fill_keys(array_keys(array_intersect_key($metadata->properties, $values)), true);
        } else {
            $provided = 0;
            foreach ($values as $name => $value) {
                $provided |= $bits[$name];
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
    public static function of(Request $dto, RequestMetadata $metadata): array
    {
        $provided = self::$fields[$dto] ?? [];
        if (!is_int($provided)) {
            return $provided;
        }

        $names = [];
        foreach ($metadata->bits ?? [] as $name => $bit) {
            if (($provided & $bit) !== 0) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    public static function has(Request $dto, RequestMetadata $metadata, string $name): bool
    {
        $provided = self::$fields[$dto] ?? [];

        return is_int($provided)
            ? ($provided & ($metadata->bits[$name] ?? 0)) !== 0
            : isset($provided[$name]);
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
