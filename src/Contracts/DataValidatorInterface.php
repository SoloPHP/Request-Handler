<?php declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

interface DataValidatorInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data, RequestHandlerInterface $handler): void;
}