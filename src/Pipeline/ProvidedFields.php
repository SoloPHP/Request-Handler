<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Solo\RequestHandler\Cache\ReflectionCache;
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
    /**
     * How many decoded name sets one class keeps — a client chooses which
     * fields it sends, so a long-running worker must not keep every shape.
     * A full class starts over, so the shapes in use now get decoded once.
     */
    private const int DECODED_PER_CLASS = 64;

    /** @var WeakMap<Request, int|array<string, true>>|null */
    private static ?WeakMap $fields = null;

    /**
     * Name sets decoded from an int entry, by class and entry: the DTOs of a
     * list share their shape, so reading them decodes it once.
     *
     * @var array<string, array<int, array<string, true>>>
     */
    private static array $decoded = [];

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
    public static function of(Request $dto): array
    {
        $provided = self::$fields[$dto] ?? [];
        if (!is_int($provided)) {
            return $provided;
        }

        return self::$decoded[$dto::class][$provided] ?? self::decode($dto::class, $provided);
    }

    /**
     * @param class-string $className
     * @return array<string, true>
     */
    private static function decode(string $className, int $provided): array
    {
        $names = [];
        foreach (ReflectionCache::of($className)->bits ?? [] as $name => $bit) {
            if (($provided & $bit) !== 0) {
                $names[$name] = true;
            }
        }

        if (count(self::$decoded[$className] ?? []) >= self::DECODED_PER_CLASS) {
            self::$decoded[$className] = [];
        }
        self::$decoded[$className][$provided] = $names;

        return $names;
    }

    /**
     * Whether the request supplied the property — one bit, with no set to
     * decode.
     */
    public static function has(Request $dto, string $name): bool
    {
        $provided = self::$fields[$dto] ?? [];

        return is_int($provided)
            ? ($provided & (ReflectionCache::of($dto::class)->bits[$name] ?? 0)) !== 0
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
