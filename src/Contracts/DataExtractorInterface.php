<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Field;

interface DataExtractorInterface
{
    /**
     * @return array<string, mixed>
     */
    public function extractRequestData(ServerRequestInterface $request): array;

    /**
     * @param array<string, mixed> $rawData
     * @param array<Field> $fields
     * @return array<string, mixed>
     */
    public function prepareData(array $rawData, array $fields): array;

    /**
     * @param array<string, mixed> $data
     * @param array<Field> $fields
     * @return array<string, mixed>
     */
    public function applyPostprocessing(array $data, array $fields): array;
}
