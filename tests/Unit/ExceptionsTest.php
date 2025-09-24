<?php

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Exceptions\{AuthorizationException, ValidationException};

final class ExceptionsTest extends TestCase
{
    public function testAuthorizationExceptionWithDefaultMessage(): void
    {
        $exception = new AuthorizationException();

        $this->assertEquals('Access denied', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }

    public function testAuthorizationExceptionWithCustomMessage(): void
    {
        $exception = new AuthorizationException('Custom access denied message');

        $this->assertEquals('Custom access denied message', $exception->getMessage());
        $this->assertEquals(403, $exception->getCode());
    }

    public function testValidationExceptionWithErrors(): void
    {
        $errors = [
            'email' => ['Email is required', 'Invalid email format'],
            'title' => ['Title is too long']
        ];

        $exception = new ValidationException($errors);

        $this->assertEquals('Validation failed: email, title', $exception->getMessage());
        $this->assertEquals(422, $exception->getCode());
        $this->assertEquals($errors, $exception->getErrors());
    }

    public function testValidationExceptionWithoutErrors(): void
    {
        $exception = new ValidationException();

        $this->assertEquals([], $exception->getErrors());
    }
}
