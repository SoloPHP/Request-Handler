<?php

namespace Solo\RequestHandler\Tests\Unit\Components;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Components\DataValidator;
use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\Field;

final class DataValidatorTest extends TestCase
{
    private ValidatorInterface $mockValidator;
    private DataValidator $validator;

    protected function setUp(): void
    {
        $this->mockValidator = $this->createMock(ValidatorInterface::class);
        $this->validator = new DataValidator($this->mockValidator);
    }

    public function testValidationWithNoRules(): void
    {
        $handler = $this->createMockHandler([Field::for('title')]);
        $data = ['title' => 'Test Title'];

        $this->expectNotToPerformAssertions();
        $this->validator->validate($data, $handler);
    }

    public function testSuccessfulValidation(): void
    {
        $fields = [Field::for('email')->validate('required|email')];
        $handler = $this->createMockHandler($fields);
        $data = ['email' => 'test@example.com'];

        $this->mockValidator
            ->expects($this->once())
            ->method('validate')
            ->with($data, ['email' => 'required|email'], [])
            ->willReturn([]);

        $this->validator->validate($data, $handler);
    }

    public function testValidationWithErrors(): void
    {
        $fields = [Field::for('email')->validate('required|email')];
        $handler = $this->createMockHandler($fields);
        $data = ['email' => 'invalid-email'];
        $errors = ['email' => ['The email field must be a valid email address.']];

        $this->mockValidator
            ->expects($this->once())
            ->method('validate')
            ->willReturn($errors);

        $this->expectException(ValidationException::class);

        try {
            $this->validator->validate($data, $handler);
        } catch (ValidationException $e) {
            $this->assertEquals($errors, $e->getErrors());
            throw $e;
        }
    }

    public function testValidationWithCustomMessages(): void
    {
        $fields = [Field::for('email')->validate('required|email')];
        $messages = ['email.required' => 'Email is mandatory'];
        $handler = $this->createMockHandler($fields, $messages);
        $data = ['email' => ''];

        $this->mockValidator
            ->expects($this->once())
            ->method('validate')
            ->with($data, ['email' => 'required|email'], $messages)
            ->willReturn([]);

        $this->validator->validate($data, $handler);
    }

    /**
     * @param array<Field> $fields
     * @param array<string, string> $messages
     */
    private function createMockHandler(array $fields, array $messages = []): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('getFields')->willReturn($fields);
        $handler->method('getMessages')->willReturn($messages);

        return $handler;
    }
}