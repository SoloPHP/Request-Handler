<?php

namespace Solo\RequestHandler\Exceptions;

use Exception;

final class ValidationException extends Exception
{
    /**
     * @param array<string, array<string>> $errors
     */
    public function __construct(
        private readonly array $errors = [],
        ?Exception             $previous = null
    )
    {
        $message = empty($errors) 
            ? "Validation failed" 
            : "Validation failed: " . implode(', ', array_keys($errors));
        $code = 422;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, array<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}