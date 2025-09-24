<?php

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Components\RequestProcessor;
use Solo\RequestHandler\Components\{DataExtractor, Authorizer, DataValidator};
use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Field;
use Solo\RequestHandler\Exceptions\{ValidationException, AuthorizationException};
use Psr\Http\Message\ServerRequestInterface;

final class RequestProcessorTest extends TestCase
{
    private RequestProcessor $processor;
    private ValidatorInterface $mockValidator;

    protected function setUp(): void
    {
        $this->mockValidator = $this->createMock(ValidatorInterface::class);

        $this->processor = new RequestProcessor(
            dataExtractor: new DataExtractor(),
            authorizer: new Authorizer(),
            validator: new DataValidator($this->mockValidator)
        );
    }

    public function testCompleteProcessingPipelineForPostRequest(): void
    {
        $request = $this->createMockRequest('POST', [
            'user' => ['email' => '  test@example.com  '],
            'title' => 'Article Title',
            'status' => 'draft'
        ]);

        $handler = $this->createMockHandler([
            Field::for('email')
                ->mapFrom('user.email')
                ->validate('required|email')
                ->preprocess(fn(mixed $v): string => trim((string)$v)),
            Field::for('title')
                ->validate('required|string|max:100'),
            Field::for('status')
                ->default('published')
                ->postprocess(fn(mixed $v): string => strtoupper((string)$v))
        ]);

        // Mock validator returns no errors (successful validation)
        $this->mockValidator
            ->method('validate')
            ->willReturn([]);

        $result = $this->processor->process($request, $handler);

        $expected = [
            'email' => 'test@example.com',
            'title' => 'Article Title',
            'status' => 'DRAFT'
        ];
        $this->assertEquals($expected, $result);
    }

    public function testProcessingFailsWithAuthorizationError(): void
    {
        $request = $this->createMockRequest('POST', ['title' => 'Test']);
        $handler = $this->createMockHandler([], authorized: false);

        $this->expectException(AuthorizationException::class);
        $this->processor->process($request, $handler);
    }

    public function testProcessingFailsWithValidationError(): void
    {
        $request = $this->createMockRequest('POST', ['email' => 'invalid']);
        $handler = $this->createMockHandler([
            Field::for('email')->validate('required|email')
        ]);

        // Mock validator returns validation errors
        $this->mockValidator
            ->method('validate')
            ->willReturn(['email' => ['Invalid email format']]);

        $this->expectException(ValidationException::class);
        $this->processor->process($request, $handler);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createMockRequest(string $method, array $data = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParsedBody')->willReturn($data);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    /**
     * @param array<Field> $fields
     */
    private function createMockHandler(array $fields, bool $authorized = true): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('getFields')->willReturn($fields);
        $handler->method('getMessages')->willReturn([]);
        $handler->method('isAuthorized')->willReturn($authorized);

        return $handler;
    }
}
