<?php

namespace Solo\RequestHandler\Exceptions;

use RuntimeException;

final class UncleanQueryException extends RuntimeException
{
    /**
     * @param array<string, mixed> $cleanedParams
     */
    public function __construct(
        public readonly array  $cleanedParams,
        public readonly string $redirectUri,
        string                 $message = 'Query parameters require cleaning.'
    )
    {
        parent::__construct($message, 302);
    }
}