<?php declare(strict_types=1);

namespace Solo\RequestHandler\Components;

use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Contracts\{
    RequestHandlerInterface,
    RequestProcessorInterface,
    DataExtractorInterface,
    AuthorizerInterface,
    DataValidatorInterface,
    QueryCleanerInterface
};

final readonly class RequestProcessor implements RequestProcessorInterface
{
    public function __construct(
        private DataExtractorInterface $dataExtractor,
        private AuthorizerInterface    $authorizer,
        private DataValidatorInterface $validator,
        private QueryCleanerInterface  $queryCleaner
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): array
    {
        // Extract raw data from request
        $rawData = $this->dataExtractor->extractRequestData($request);

        // Clean GET query parameters if needed
        if ($request->getMethod() === 'GET') {
            $this->queryCleaner->ensureCleanQuery($request, $rawData, $handler);
        }

        // Authorization check
        $this->authorizer->authorize($handler);

        // Prepare data with field mapping and preprocessing
        $preparedData = $this->dataExtractor->prepareData($rawData, $handler->getFields());

        // Validate prepared data
        $this->validator->validate($preparedData, $handler);

        // Apply postprocessing and return final result
        return $this->dataExtractor->applyPostprocessing($preparedData, $handler->getFields());
    }
}