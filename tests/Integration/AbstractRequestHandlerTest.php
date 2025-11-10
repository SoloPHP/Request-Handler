<?php

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\AbstractRequestHandler;
use Solo\RequestHandler\Field;
use Solo\RequestHandler\Helpers\ParameterParser;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AbstractRequestHandlerTest extends TestCase
{
    public function testConcreteRequestHandlerImplementation(): void
    {
        // Simple mock - always passes validation
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        $handler = new TestRequestHandler($validator);
        $request = $this->createMockRequest('POST', [
            'meta' => ['user' => ['email' => 'test@example.com']],
            'title' => '  Test Article  '
        ]);

        $result = $handler->handle($request);

        $expected = [
            'email' => 'test@example.com',
            'title' => 'Test Article',
            'status' => 'PUBLISHED'
        ];
        $this->assertEquals($expected, $result);
    }

    public function testHandlerDefaultsCalculation(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new TestRequestHandler($validator);

        $fields = $handler->getFields();
        $statusField = null;
        foreach ($fields as $field) {
            if ($field->name === 'status') {
                $statusField = $field;
                break;
            }
        }

        $this->assertNotNull($statusField);
        $this->assertEquals('published', $statusField->default);
    }

    public function testValidationErrorHandling(): void
    {
        // Mock validator that returns errors
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([
            'email' => ['Email is required'],
            'title' => ['Title must be a string']
        ]);

        $handler = new TestRequestHandler($validator);
        $request = $this->createMockRequest('POST', []);

        $this->expectException(\Solo\RequestHandler\Exceptions\ValidationException::class);
        $handler->handle($request);
    }

    public function testRepositoryHelperMethods(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        $handler = new RepositoryHelperTestHandler($validator);
        $request = $this->createMockRequest('GET', [], [
            'sort' => '-created_at',
            'filter' => ['status' => 'active', 'role' => 'admin'],
            'page' => '2',
            'per_page' => '25'
        ]);

        $result = $handler->handle($request);

        $expected = [
            'sort' => ['created_at' => 'DESC'],
            'filter' => ['filter' => ['status' => 'active', 'role' => 'admin']],
            'page' => 2,
            'per_page' => 25
        ];

        $this->assertEquals($expected, $result);
    }

    public function testParseSortParameterAscending(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new RepositoryHelperTestHandler($validator);

        $request = $this->createMockRequest('GET', [], ['sort' => 'name']);
        $result = $handler->handle($request);

        $this->assertEquals(['name' => 'ASC'], $result['sort']);
    }

    public function testParseSortParameterDescending(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new RepositoryHelperTestHandler($validator);

        $request = $this->createMockRequest('GET', [], ['sort' => '-created_at']);
        $result = $handler->handle($request);

        $this->assertEquals(['created_at' => 'DESC'], $result['sort']);
    }

    public function testParseSortParameterEmpty(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new RepositoryHelperTestHandler($validator);

        $request = $this->createMockRequest('GET', [], []);
        $result = $handler->handle($request);

        $this->assertArrayHasKey('sort', $result);
        $this->assertNull($result['sort']);
    }

    public function testParseFilterParameterWithFilter(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new RepositoryHelperTestHandler($validator);

        $filter = ['status' => 'active', 'role' => 'admin'];
        $request = $this->createMockRequest('GET', [], ['filter' => $filter]);
        $result = $handler->handle($request);

        $expected = ['filter' => ['status' => 'active', 'role' => 'admin']];
        $this->assertEquals($expected, $result['filter']);
    }

    public function testParseFilterParameterEmpty(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $handler = new RepositoryHelperTestHandler($validator);

        $request = $this->createMockRequest('GET', [], []);
        $result = $handler->handle($request);

        $this->assertArrayHasKey('filter', $result);
        $this->assertEquals([], $result['filter']);
    }

    public function testTransformMethodWithSimpleTransformation(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        $handler = new TransformTestHandler($validator);
        $request = $this->createMockRequest('POST', ['status' => 'active']);

        $result = $handler->handle($request);

        // 'status' should be transformed to 'is_active'
        $this->assertArrayHasKey('is_active', $result);
        $this->assertEquals(1, $result['is_active']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testTransformMethodWithDependencyInjection(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        $mockService = $this->createMock(TestService::class);
        $mockService->method('expandIds')->willReturn([1, 2, 3, 4]);

        $handler = new TransformWithDependencyHandler($validator, $mockService);
        $request = $this->createMockRequest('POST', ['category_id' => 1]);

        $result = $handler->handle($request);

        // Single category_id should be expanded to array
        $this->assertIsArray($result['category_id']);
        $this->assertEquals([1, 2, 3, 4], $result['category_id']);
    }

    public function testTransformMethodWithCrossFieldLogic(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        $handler = new CrossFieldTransformHandler($validator);
        $request = $this->createMockRequest('POST', [
            'integration_type' => 'shopee',
            'sync_status' => 'synced'
        ]);

        $result = $handler->handle($request);

        // Original fields should be removed
        $this->assertArrayNotHasKey('integration_type', $result);
        $this->assertArrayNotHasKey('sync_status', $result);

        // New fields should be created
        $this->assertArrayHasKey('mappings.integration_type', $result);
        $this->assertEquals('shopee', $result['mappings.integration_type']);
        $this->assertArrayHasKey('mappings.external_id', $result);
        $this->assertEquals(['!=', null], $result['mappings.external_id']);
    }

    public function testTransformMethodNotCalledWhenNotOverridden(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn([]);

        // TestRequestHandler doesn't override transform()
        $handler = new TestRequestHandler($validator);
        $request = $this->createMockRequest('POST', [
            'meta' => ['user' => ['email' => 'test@example.com']],
            'title' => 'Test'
        ]);

        $result = $handler->handle($request);

        // Data should pass through unchanged (except field processing)
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $queryParams
     */
    private function createMockRequest(string $method, array $data, array $queryParams = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParsedBody')->willReturn($data);
        $request->method('getQueryParams')->willReturn($queryParams);

        return $request;
    }
}

final readonly class TestRequestHandler extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            'email' => Field::for('email')
                ->mapFrom('meta.user.email')
                ->validate('required|email'),
            'title' => Field::for('title')
                ->validate('required|string')
                ->preprocess(fn(mixed $v): string => trim((string)$v)),
            'status' => Field::for('status')
                ->default('published')
                ->postprocess(fn(mixed $v): string => strtoupper((string)$v))
        ];
    }

    protected function authorize(): bool
    {
        return true;
    }
}

final readonly class RepositoryHelperTestHandler extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            'page' => Field::for('page')
                ->default(1)
                ->validate('integer|min:1')
                ->postprocess(fn($v) => (int)$v),
            'per_page' => Field::for('per_page')
                ->default(15)
                ->validate('integer|min:1|max:100')
                ->postprocess(fn($v) => (int)$v),
            'sort' => Field::for('sort')
                ->default(null)
                ->postprocess(fn($v) => ParameterParser::sort($v)),
            'filter' => Field::for('filter')
                ->default(null)
                ->postprocess(fn($v) => ParameterParser::filter($v)),
        ];
    }

    protected function authorize(): bool
    {
        return true;
    }
}

// Test handlers for transform() method
final readonly class TransformTestHandler extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            Field::for('status')->validate('string|in:active,inactive')
        ];
    }

    protected function transform(array $data): array
    {
        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'active' ? 1 : 0;
            unset($data['status']);
        }
        return $data;
    }
}

final readonly class TransformWithDependencyHandler extends AbstractRequestHandler
{
    public function __construct(
        ValidatorInterface $validator,
        private TestService $service
    ) {
        parent::__construct($validator);
    }

    protected function fields(): array
    {
        return [
            Field::for('category_id')->validate('integer|min:1')
        ];
    }

    protected function transform(array $data): array
    {
        if (isset($data['category_id'])) {
            $data['category_id'] = $this->service->expandIds($data['category_id']);
        }
        return $data;
    }
}

final readonly class CrossFieldTransformHandler extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            Field::for('integration_type')->validate('string'),
            Field::for('sync_status')->validate('string|in:synced,sync_error,disabled')
        ];
    }

    protected function transform(array $data): array
    {
        $integrationType = $data['integration_type'] ?? null;
        $syncStatus = $data['sync_status'] ?? null;
        unset($data['integration_type'], $data['sync_status']);

        if ($integrationType !== null) {
            $data['mappings.integration_type'] = $integrationType;
        }

        if ($syncStatus === 'synced') {
            $data['mappings.external_id'] = ['!=', null];
            $data['mappings.last_error'] = null;
        }

        return $data;
    }
}

// Mock service for testing DI in transform()
interface TestService
{
    /**
     * @return array<int>
     */
    public function expandIds(int $id): array;
}
