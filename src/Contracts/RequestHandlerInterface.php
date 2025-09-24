<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Solo\RequestHandler\Field;

interface RequestHandlerInterface
{
    /**
     * @return array<Field>
     */
    public function getFields(): array;

    /**
     * @return array<string, string>
     */
    public function getMessages(): array;

    public function isAuthorized(): bool;
}
