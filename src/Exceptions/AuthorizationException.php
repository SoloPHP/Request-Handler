<?php declare(strict_types=1);

namespace Solo\RequestHandler\Exceptions;

use Exception;

final class AuthorizationException extends Exception
{
    public function __construct(string $message = "Access denied", int $code = 403, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}