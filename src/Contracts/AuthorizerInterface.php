<?php declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

interface AuthorizerInterface
{
    public function authorize(RequestHandlerInterface $handler): void;
}