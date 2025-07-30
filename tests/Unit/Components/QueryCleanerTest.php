<?php

namespace Solo\RequestHandler\Tests\Unit\Components;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Components\QueryCleaner;
use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\RequestHandler\Exceptions\UncleanQueryException;
use Solo\RequestHandler\Field;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class QueryCleanerTest extends TestCase
{
    private QueryCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new QueryCleaner();
    }

    public function testCleanQueryWithNoChangesNeeded(): void
    {
        $data = ['page' => '1', 'limit' => '10'];
        $fields = [Field::for('page'), Field::for('limit')];
        $defaults = [];

        $handler = $this->createMockHandler($fields, $defaults);
        $request = $this->createMockRequest();

        $this->expectNotToPerformAssertions();
        $this->cleaner->ensureCleanQuery($request, $data, $handler);
    }

    public function testCleanQueryRemovesUnallowedFields(): void
    {
        $data = ['page' => '1', 'unauthorized' => 'value'];
        $fields = [Field::for('page')];
        $defaults = [];

        $handler = $this->createMockHandler($fields, $defaults);
        $request = $this->createMockRequest();

        $this->expectException(UncleanQueryException::class);

        try {
            $this->cleaner->ensureCleanQuery($request, $data, $handler);
        } catch (UncleanQueryException $e) {
            $this->assertEquals(['page' => '1'], $e->cleanedParams);
            throw $e;
        }
    }

    public function testCleanQueryRemovesDefaultValues(): void
    {
        $data = ['page' => '1', 'status' => 'active'];
        $fields = [Field::for('page'), Field::for('status')];
        $defaults = ['status' => 'active'];

        $handler = $this->createMockHandler($fields, $defaults);
        $request = $this->createMockRequest();

        $this->expectException(UncleanQueryException::class);

        try {
            $this->cleaner->ensureCleanQuery($request, $data, $handler);
        } catch (UncleanQueryException $e) {
            $this->assertEquals(['page' => '1'], $e->cleanedParams);
            $this->assertStringContainsString('page=1', $e->redirectUri);
            $this->assertStringNotContainsString('status=active', $e->redirectUri);
            throw $e;
        }
    }

    /**
     * @param array<Field> $fields
     * @param array<string, mixed> $defaults
     */
    private function createMockHandler(array $fields, array $defaults): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('getFields')->willReturn($fields);
        $handler->method('getDefaults')->willReturn($defaults);

        return $handler;
    }

    private function createMockRequest(): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('withQuery')
            ->willReturnCallback(function (string $query) {
                $newUri = $this->createMock(UriInterface::class);
                $newUri->method('__toString')->willReturn('https://example.com/test?' . $query);
                return $newUri;
            });
        $uri->method('__toString')->willReturn('https://example.com/test');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}