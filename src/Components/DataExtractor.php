<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Components;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Contracts\DataExtractorInterface;
use Solo\RequestHandler\Field;

final class DataExtractor implements DataExtractorInterface
{
    private const FIELD_NOT_FOUND = '__FIELD_NOT_FOUND__';
    /**
     * @return array<string, mixed>
     */
    public function extractRequestData(ServerRequestInterface $request): array
    {
        return match ($request->getMethod()) {
            'GET' => $request->getQueryParams(),
            default => array_merge(
                $request->getQueryParams(),
                (array)($request->getParsedBody() ?? [])
            ),
        };
    }

    /**
     * @param array<string, mixed> $rawData
     * @param array<Field> $fields
     * @return array<string, mixed>
     */
    public function prepareData(array $rawData, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $rawValue = $this->extractNestedValue($rawData, $field->inputName);

            // Добавляем поле только если оно было найдено ИЛИ есть default значение
            if ($rawValue !== self::FIELD_NOT_FOUND) {
                $result[$field->name] = $field->processPre($rawValue);
            } elseif ($field->hasDefault()) {
                $result[$field->name] = $field->processPre($field->default);
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<Field> $fields
     * @return array<string, mixed>
     */
    public function applyPostprocessing(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field->name, $data)) {
                $data[$field->name] = $field->processPost($data[$field->name]);
            }
        }
        return $data;
    }

    /**
     * Extract value from nested array structure using dot notation path
     *
     * @param array<string, mixed> $data
     */
    private function extractNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return self::FIELD_NOT_FOUND;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
