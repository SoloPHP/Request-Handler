<?php declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Psr\Http\Message\ServerRequestInterface;

interface RequestProcessorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): array;
}