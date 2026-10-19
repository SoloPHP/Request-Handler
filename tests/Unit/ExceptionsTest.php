<?php

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Exceptions\{ConfigurationException, ValidationException};

final class ExceptionsTest extends TestCase
{
    public function testValidationExceptionWithErrors(): void
    {
        $errors = [
            'email' => [['rule' => 'required'], ['rule' => 'email']],
            'title' => [['rule' => 'max', 'params' => ['255']]]
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

    public function testConfigurationExceptionInvalidGenerator(): void
    {
        $exception = ConfigurationException::invalidGenerator(
            'App\\Request',
            'id',
            'InvalidGenerator',
            'class does not exist'
        );

        $this->assertStringContainsString("has invalid generator 'InvalidGenerator'", $exception->getMessage());
        $this->assertStringContainsString('App\\Request::$id', $exception->getMessage());
        $this->assertStringContainsString('class does not exist', $exception->getMessage());
    }

    public function testConfigurationExceptionInvalidItems(): void
    {
        $exception = ConfigurationException::invalidItems(
            'App\\OrderRequest',
            'items',
            'NonExistentClass',
            'class does not exist'
        );

        $this->assertStringContainsString("has invalid items class 'NonExistentClass'", $exception->getMessage());
        $this->assertStringContainsString('App\\OrderRequest::$items', $exception->getMessage());
        $this->assertStringContainsString('class does not exist', $exception->getMessage());
    }

    public function testConfigurationExceptionItemsRequiresArrayType(): void
    {
        $exception = ConfigurationException::itemsRequiresArrayType(
            'App\\OrderRequest',
            'items',
            'string'
        );

        $this->assertStringContainsString("has 'items' but type is 'string'", $exception->getMessage());
        $this->assertStringContainsString('App\\OrderRequest::$items', $exception->getMessage());
    }

    public function testConfigurationExceptionGeneratorConflict(): void
    {
        $exception = ConfigurationException::generatorConflict(
            'App\\OrderRequest',
            'items',
            'Items'
        );

        $this->assertStringContainsString('has both #[Generator] and #[Items]', $exception->getMessage());
        $this->assertStringContainsString('App\\OrderRequest::$items', $exception->getMessage());
    }
}
