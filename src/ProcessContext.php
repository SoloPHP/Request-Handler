<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

final readonly class ProcessContext
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $routeParams
     */
    public function __construct(
        public array $config = [],
        public array $routeParams = [],
    ) {
    }
}
