<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Attributes;

use Attribute;

/**
 * Marks a class as a request DTO that can be hydrated from HTTP request
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsRequest
{
}
