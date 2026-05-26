<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

/**
 * Where a property's value is read from.
 *
 * Source is normally inferred from the handle* method being called
 * (`handleBody` → Body, `handleQuery` → Query). Properties that need a
 * non-default source declare it via `#[FromRoute]` or `#[FromContext]`.
 */
enum FieldSource
{
    case Body;
    case Query;
    case Route;
    case Context;
}
