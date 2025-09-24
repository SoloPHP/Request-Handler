<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Contracts\{
    RequestProcessorInterface,
    DataExtractorInterface,
    AuthorizerInterface,
    RequestHandlerInterface
};
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Components\{
    RequestProcessor,
    DataExtractor,
    Authorizer,
    DataValidator
};

/**
 * Abstract base class for request handlers that provides a complete processing pipeline.
 *
 * This class implements the main request handling logic with the following steps:
 * 1. Data extraction from HTTP request
 * 2. Authorization check
 * 3. Data preprocessing and validation
 * 4. Postprocessing of validated data
 *
 * Extend this class and implement the `fields()` method to define your request schema.
 * Optionally override `authorize()` and `messages()` methods for custom behavior.
 */
abstract readonly class AbstractRequestHandler implements RequestHandlerInterface
{
    private RequestProcessorInterface $processor;

    public function __construct(ValidatorInterface $validator)
    {
        $this->processor = new RequestProcessor(
            dataExtractor: $this->createDataExtractor(),
            authorizer: $this->createAuthorizer(),
            validator: new DataValidator($validator)
        );
    }

    /**
     * Factory method for DataExtractor - can be overridden for testing/customization
     */
    protected function createDataExtractor(): DataExtractorInterface
    {
        return new DataExtractor();
    }

    /**
     * Factory method for Authorizer - can be overridden for testing/customization
     */
    protected function createAuthorizer(): AuthorizerInterface
    {
        return new Authorizer();
    }


    /**
     * @return array<string, mixed>
     */
    public function handle(ServerRequestInterface $request): array
    {
        $data = $this->processor->process($request, $this);
        return $this->structureResponse($data, $this->fields());
    }

    /**
     * Structure flat data according to fields structure
     * @param array<string, mixed> $data
     * @param array<string, mixed> $structure
     * @return array<string, mixed>
     */
    private function structureResponse(array $data, array $structure): array
    {
        $result = [];

        foreach ($structure as $key => $value) {
            if ($value instanceof Field) {
                if (array_key_exists($value->name, $data)) {
                    // Use field name as key for flat arrays (numeric keys),
                    // preserve original key for associative arrays
                    $resultKey = is_numeric($key) ? $value->name : $key;
                    $result[$resultKey] = $data[$value->name];
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->structureResponse($data, $value);
            }
        }

        return $result;
    }

    /**
     * @return array<Field>
     */
    public function getFields(): array
    {
        return $this->flattenFields($this->fields());
    }

    /**
     * Recursively flatten nested structure to array of Fields
     * @param array<string, mixed> $fields
     * @return array<Field>
     */
    private function flattenFields(array $fields): array
    {
        $flattened = [];
        foreach ($fields as $value) {
            if ($value instanceof Field) {
                $flattened[] = $value;
            } elseif (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenFields($value));
            }
        }
        return $flattened;
    }

    /**
     * @return array<string, string>
     */
    public function getMessages(): array
    {
        return $this->messages();
    }

    public function isAuthorized(): bool
    {
        return $this->authorize();
    }

    /**
     * @return array<Field>
     */
    abstract protected function fields(): array;

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    protected function authorize(): bool
    {
        return true;
    }
}
