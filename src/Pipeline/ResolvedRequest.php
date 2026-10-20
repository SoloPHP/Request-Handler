<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Pipeline;

use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\Exceptions\ValidationException;

/**
 * Normalized, validated and decoded input awaiting finalization.
 * Children are finalized into DTOs only after the entire tree is valid.
 *
 * @phpstan-import-type ValidationErrors from ValidationException
 * @internal
 */
final readonly class ResolvedRequest
{
    /**
     * @param array<string, mixed>                 $values   Present fields
     * @param array<string, list<ResolvedRequest>> $children Per property, in input order
     * @param ValidationErrors                    $errors   Errors of this object and its children
     * @param array<string, mixed>                 $route    Route available to this node's post-processors
     * @param array<string, mixed>                 $context  Application context passed through the tree
     */
    public function __construct(
        public RequestMetadata $metadata,
        public array $values,
        public array $children,
        public array $errors,
        public array $route,
        public array $context,
    ) {
    }
}
