<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\RequestHandler;
use Solo\RequestHandler\Traits\DynamicProperties;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RequestHandlerTest extends TestCase
{
    private RequestHandler $handler;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->handler = new RequestHandler($this->validator);
    }

    public function testHandleExtractsAndValidatesData(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test Item', 'price' => '10.50']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Test Item', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test Item', $dto->name);
        $this->assertEquals(10.5, $dto->price);
        $this->assertTrue(isset($dto->name));
        $this->assertTrue(isset($dto->price));
    }

    public function testHandleThrowsValidationException(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(['name' => ['Required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handle(TestRequest::class, $request);
    }

    public function testHandleWithMissingOptionalField(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertFalse(isset($dto->description));
    }

    public function testHandleWithDefaultValue(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals(1, $dto->page);
    }

    public function testHandleWithNestedMapping(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'user' => ['id' => 123],
            'name' => 'Test'
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals(123, $dto->userId);
    }

    public function testHandleWithPreAndPostProcessing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => '  Test  ',
            'slug' => 'Test Title'
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(ProcessingRequest::class, $request);

        $this->assertEquals('Test', $dto->name); // Trimmed
        $this->assertEquals('test-title', $dto->slug); // Slugified
    }
}

/**
 * @property string $name
 * @property float $price
 * @property string $description
 * @property int $page
 * @property int $userId
 */
#[AsRequest]
#[Field('name', 'required|string')]
#[Field('price', 'required|numeric', cast: 'float')]
#[Field('description', 'nullable|string')]
#[Field('page', 'integer', default: 1, hasDefault: true)]
#[Field('userId', 'integer', mapFrom: 'user.id')]
final class TestRequest
{
    use DynamicProperties;
}

/**
 * @property string $name
 * @property string $slug
 */
#[AsRequest]
#[Field('name', 'string', preProcess: 'trim')]
#[Field('slug', 'string', postProcess: 'slugify')] // Simplified for test
final class ProcessingRequest
{
    use DynamicProperties;

    public static function slugify(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
}
