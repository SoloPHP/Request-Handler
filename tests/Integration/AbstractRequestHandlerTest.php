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
