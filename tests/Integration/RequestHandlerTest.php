<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\DynamicRequest;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\RequestHandler;
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

    public function testHandleWithGetRequest(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn(['name' => 'Test', 'price' => '20']);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals(20.0, $dto->price);
    }

    public function testHandleWithCustomCaster(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['id' => '5']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(CustomCasterRequest::class, $request);

        $this->assertEquals(10, $dto->id); // 5 * 2 = 10
    }

    public function testHandleWithPreProcessorInterface(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PreProcessorRequest::class, $request);

        $this->assertEquals('pre_test', $dto->value);
    }

    public function testHandleWithPostProcessorInterface(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PostProcessorRequest::class, $request);

        $this->assertEquals('test_post', $dto->value);
    }

    public function testHandleWithEmptyFieldHavingDefault(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test', 'page' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals(1, $dto->page); // Default value
    }

    public function testHandleWithEmptyFieldWithoutDefault(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test', 'description' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertNull($dto->description);
    }

    public function testHandleWithCasterAsProcessor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(CasterAsProcessorRequest::class, $request);

        $this->assertEquals('casted_test', $dto->value);
    }

    public function testHandleWithUnknownProcessor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(UnknownProcessorRequest::class, $request);

        // Unknown processor returns value unchanged
        $this->assertEquals('test', $dto->value);
    }

    public function testGroupReturnsFieldsWithSameGroup(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn([
            'search' => 'test',
            'deleted' => 'only',
            'page' => '2',
            'per_page' => '10'
        ]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GroupedRequest::class, $request);

        $criteria = $dto->group('criteria');
        $this->assertEquals(['search' => 'test', 'deleted' => 'only'], $criteria);
        $this->assertArrayNotHasKey('page', $criteria);
        $this->assertArrayNotHasKey('per_page', $criteria);
    }

    public function testGroupReturnsEmptyArrayWhenNoFieldsMatch(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn(['page' => '1']);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GroupedRequest::class, $request);

        $this->assertEquals([], $dto->group('criteria'));
    }
}

/**
 * @property string $name
 * @property float $price
 * @property string|null $description
 * @property int $page
 * @property int $userId
 */
#[AsRequest]
#[Field('name', 'required|string')]
#[Field('price', 'required|numeric', cast: 'float')]
#[Field('description', 'nullable|string')]
#[Field('page', 'integer', default: 1)]
#[Field('userId', 'integer', mapFrom: 'user.id')]
final class TestRequest extends DynamicRequest
{
}

/**
 * @property string $name
 * @property string $slug
 */
#[AsRequest]
#[Field('name', 'string', preProcess: 'trim')]
#[Field('slug', 'string', postProcess: 'slugify')]
final class ProcessingRequest extends DynamicRequest
{
    public static function slugify(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
}

/**
 * @property int $id
 */
#[AsRequest]
#[Field('id', 'required|integer', cast: CustomCaster::class)]
final class CustomCasterRequest extends DynamicRequest
{
}

/**
 * @property string $value
 */
#[AsRequest]
#[Field('value', 'required', preProcess: TestPreProcessor::class)]
final class PreProcessorRequest extends DynamicRequest
{
}

/**
 * @property string $value
 */
#[AsRequest]
#[Field('value', 'required', postProcess: TestPostProcessor::class)]
final class PostProcessorRequest extends DynamicRequest
{
}

/**
 * @property string $search
 * @property string $deleted
 * @property int $page
 * @property int $per_page
 */
#[AsRequest]
#[Field('search', 'nullable|string', group: 'criteria')]
#[Field('deleted', 'nullable|in:only,with', group: 'criteria')]
#[Field('page', 'integer|min:1', cast: 'int', default: 1)]
#[Field('per_page', 'integer|min:1|max:100', cast: 'int', default: 20)]
final class GroupedRequest extends DynamicRequest
{
}

final class CustomCaster implements \Solo\RequestHandler\Casters\CasterInterface
{
    public function cast(mixed $value): int
    {
        return (int) $value * 2;
    }
}

final class TestPreProcessor implements \Solo\RequestHandler\Casters\PostProcessorInterface
{
    public function process(mixed $value): string
    {
        return 'pre_' . $value;
    }
}

final class TestPostProcessor implements \Solo\RequestHandler\Casters\PostProcessorInterface
{
    public function process(mixed $value): string
    {
        return $value . '_post';
    }
}

final class TestCasterAsProcessor implements \Solo\RequestHandler\Casters\CasterInterface
{
    public function cast(mixed $value): string
    {
        return 'casted_' . $value;
    }
}

/**
 * @property string $value
 */
#[AsRequest]
#[Field('value', 'required', preProcess: TestCasterAsProcessor::class)]
final class CasterAsProcessorRequest extends DynamicRequest
{
}

/**
 * @property string $value
 */
#[AsRequest]
#[Field('value', 'required', preProcess: 'nonExistentHandler')]
final class UnknownProcessorRequest extends DynamicRequest
{
}
