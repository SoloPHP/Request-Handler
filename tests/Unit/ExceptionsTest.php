<?php

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Exceptions\{AuthorizationException, ValidationException, UncleanQueryException};

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

    public function testUncleanQueryException(): void
    {
        $cleanedParams = ['page' => '1'];
        $redirectUri = 'https://example.com/test?page=1';

        $exception = new UncleanQueryException($cleanedParams, $redirectUri);

        $this->assertEquals('Query parameters require cleaning.', $exception->getMessage());
        $this->assertEquals(302, $exception->getCode());
        $this->assertEquals($cleanedParams, $exception->cleanedParams);
        $this->assertEquals($redirectUri, $exception->redirectUri);
    }

    public function testUncleanQueryExceptionWithCustomMessage(): void
    {
        $exception = new UncleanQueryException([], '', 'Custom cleaning message');

        $this->assertEquals('Custom cleaning message', $exception->getMessage());
    }
}