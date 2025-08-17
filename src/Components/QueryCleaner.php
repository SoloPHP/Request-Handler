<?php declare(strict_types=1);

namespace Solo\RequestHandler\Components;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\RequestHandler\Contracts\QueryCleanerInterface;
use Solo\RequestHandler\Exceptions\UncleanQueryException;
use Solo\RequestHandler\Field;

final class QueryCleaner implements QueryCleanerInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function ensureCleanQuery(
        ServerRequestInterface $request,
        array $data,
        RequestHandlerInterface $handler
    ): void {
        $allowedData = $this->filterAllowedFields($data, $handler->getFields());
        $cleanedData = $this->removeDefaultValues($allowedData, $handler->getDefaults());

        if ($cleanedData !== $data) {
            $uri = $request->getUri()->withQuery(http_build_query($cleanedData));
            throw new UncleanQueryException($cleanedData, (string)$uri);
        }
    }

    /**
     * Filter data to keep only fields defined in handler
     *
     * @param array<string, mixed> $data
     * @param array<Field> $fields
     * @return array<string, mixed>
     */
    private function filterAllowedFields(array $data, array $fields): array
    {
        $allowedKeys = [];
        foreach ($fields as $field) {
            $allowedKeys[$field->name] = true;
        }
        return array_intersect_key($data, $allowedKeys);
    }

    /**
     * Remove parameters that match default values
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function removeDefaultValues(array $data, array $defaults): array
    {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $defaults) && (string)$value === (string)$defaults[$key]) {
                unset($data[$key]);
            }
        }
        return $data;
    }
}