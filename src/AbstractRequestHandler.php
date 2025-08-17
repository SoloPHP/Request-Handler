<?php declare(strict_types=1);

namespace Solo\RequestHandler;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Contracts\{
    RequestProcessorInterface,
    DataExtractorInterface,
    AuthorizerInterface,
    QueryCleanerInterface,
    RequestHandlerInterface
};
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Components\{
    RequestProcessor,
    DataExtractor,
    Authorizer,
    DataValidator,
    QueryCleaner
};

/**
 * Abstract base class for request handlers that provides a complete processing pipeline.
 * 
 * This class implements the main request handling logic with the following steps:
 * 1. Data extraction from HTTP request
 * 2. Query parameter cleaning for GET requests
 * 3. Authorization check
 * 4. Data preprocessing and validation
 * 5. Postprocessing of validated data
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
            validator: new DataValidator($validator),
            queryCleaner: $this->createQueryCleaner()
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
     * Factory method for QueryCleaner - can be overridden for testing/customization
     */
    protected function createQueryCleaner(): QueryCleanerInterface
    {
        return new QueryCleaner();
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ServerRequestInterface $request): array
    {
        return $this->processor->process($request, $this);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        $defaults = [];
        foreach ($this->fields() as $field) {
            if ($field->default !== null) {
                $defaults[$field->name] = $field->default;
            }
        }
        return $defaults;
    }

    /**
     * @return array<Field>
     */
    public function getFields(): array
    {
        return $this->fields();
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