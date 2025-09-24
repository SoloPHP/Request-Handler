<?php

namespace Solo\RequestHandler\Tests\Unit\Components;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Components\Authorizer;
use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\RequestHandler\Exceptions\AuthorizationException;

final class AuthorizerTest extends TestCase
{
    private Authorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new Authorizer();
    }

    public function testAuthorizeWithPermittedHandler(): void
    {
        $handler = $this->createMockHandler(authorized: true);

        $this->expectNotToPerformAssertions();
        $this->authorizer->authorize($handler);
    }

    public function testAuthorizeWithUnauthorizedHandler(): void
    {
        $handler = $this->createMockHandler(authorized: false);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Unauthorized request.');
        $this->expectExceptionCode(403);

        $this->authorizer->authorize($handler);
    }

    private function createMockHandler(bool $authorized): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('isAuthorized')->willReturn($authorized);

        return $handler;
    }
}
