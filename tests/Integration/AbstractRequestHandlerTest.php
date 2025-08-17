<?php

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\AbstractRequestHandler;
use Solo\RequestHandler\Field;
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

        $defaults = $handler->getDefaults();

        $this->assertEquals(['status' => 'published'], $defaults);
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

    /**
     * @param array<string, mixed> $data
     */
    private function createMockRequest(string $method, array $data): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParsedBody')->willReturn($data);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}

final readonly class TestRequestHandler extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            Field::for('email')
                ->mapFrom('meta.user.email')
                ->validate('required|email'),
            Field::for('title')
                ->validate('required|string')
                ->preprocess(fn(mixed $v): string => trim((string)$v)),
            Field::for('status')
                ->default('published')
                ->postprocess(fn(mixed $v): string => strtoupper((string)$v))
        ];
    }

    protected function authorize(): bool
    {
        return true;
    }
}