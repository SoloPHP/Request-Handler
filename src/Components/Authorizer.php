<?php

namespace Solo\RequestHandler\Components;

use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\RequestHandler\Contracts\AuthorizerInterface;
use Solo\RequestHandler\Exceptions\AuthorizationException;

final class Authorizer implements AuthorizerInterface
{
    public function authorize(RequestHandlerInterface $handler): void
    {
        if (!$handler->isAuthorized()) {
            throw new AuthorizationException('Unauthorized request.');
        }
    }
}
