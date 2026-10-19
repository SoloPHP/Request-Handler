<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Solo\RequestHandler\Request;
use WeakMap;

/**
 * What the request supplied for each DTO `RequestHandler` built — kept
 * beside the instance rather than in it, so `(array)`, `serialize()` and
 * property normalizers see only the DTO's own fields. An entry lives as long
 * as its DTO.
 *
 * @internal Read it through `Request::provided()`.
 */
final class ProvidedFields
{
    /** @var WeakMap<Request, array<string, true>>|null */
    private static ?WeakMap $fields = null;

    /**
     * @param array<string, true> $names
     */
    public static function record(Request $dto, array $names): void
    {
        self::$fields ??= new WeakMap();
        self::$fields[$dto] = $names;
    }

    /**
     * @return array<string, true>
     */
    public static function of(Request $dto): array
    {
        return self::$fields[$dto] ?? [];
    }
}
