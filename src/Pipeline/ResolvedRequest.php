<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\InputBags;

/**
 * One request object between the passes of `RequestHandler`: input read,
 * pre-processed, validated and cast (resolve), then post-processed and
 * custom-cast (transform). It is hydrated into a DTO only when the whole tree
 * is free of errors.
 *
 * @phpstan-import-type ValidationErrors from ValidationException
 * @internal
 */
final readonly class ResolvedRequest
{
    /**
     * @param array<string, mixed>                                              $values   Present fields
     * @param array<string, ResolvedRequest|array<int|string, ResolvedRequest>> $children Nested DTOs; `#[Items]`
     *                                                                                    lists by input key
     * @param ValidationErrors                                                  $errors   Errors of this object
     *                                                                                    and its children
     */
    public function __construct(
        public RequestMetadata $metadata,
        public InputBags $bags,
        public array $values,
        public array $children,
        public array $errors,
    ) {
    }
}
