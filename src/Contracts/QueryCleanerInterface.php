<?php declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Psr\Http\Message\ServerRequestInterface;

interface QueryCleanerInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function ensureCleanQuery(
        ServerRequestInterface $request,
        array $data,
        RequestHandlerInterface $handler
    ): void;
}