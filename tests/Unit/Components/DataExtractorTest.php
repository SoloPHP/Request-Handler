<?php

namespace Solo\RequestHandler\Tests\Unit\Components;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Components\DataExtractor;
use Solo\RequestHandler\Field;

final class DataExtractorTest extends TestCase
{
    private DataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DataExtractor();
    }

    public function testExtractRequestDataFromGetRequest(): void
    {
        $request = $this->createMockRequest('GET', queryParams: ['page' => '1', 'limit' => '10']);

        $data = $this->extractor->extractRequestData($request);

        $this->assertEquals(['page' => '1', 'limit' => '10'], $data);
    }

    public function testExtractRequestDataFromPostRequest(): void
    {
        $request = $this->createMockRequest(
            'POST',
            parsedBody: ['title' => 'Article Title', 'content' => 'Content'],
            queryParams: ['draft' => 'true']
        );

        $data = $this->extractor->extractRequestData($request);

        $expected = [
            'title' => 'Article Title',
            'content' => 'Content',
            'draft' => 'true'
        ];
        $this->assertEquals($expected, $data);
    }

    public function testPostBodyTakesPriorityOverQueryParams(): void
    {
        $request = $this->createMockRequest(
            'POST',
            parsedBody: ['title' => 'Post Title'],
            queryParams: ['title' => 'Query Title']
        );

        $data = $this->extractor->extractRequestData($request);

        $this->assertEquals('Post Title', $data['title']);
    }

    public function testPrepareDataWithSimpleFields(): void
    {
        $rawData = ['title' => '  Article Title  ', 'status' => 'draft'];
        $fields = [
            Field::for('title')->preprocess(fn(mixed $v): string => trim((string)$v)),
            Field::for('status')->default('published')
        ];

        $prepared = $this->extractor->prepareData($rawData, $fields);

        $this->assertEquals(['title' => 'Article Title', 'status' => 'draft'], $prepared);
    }

    public function testPrepareDataWithNestedMapping(): void
    {
        $rawData = [
            'user' => [
                'profile' => [
                    'email' => 'test@example.com'
                ]
            ]
        ];
        $fields = [Field::for('email')->mapFrom('user.profile.email')];

        $prepared = $this->extractor->prepareData($rawData, $fields);

        $this->assertEquals(['email' => 'test@example.com'], $prepared);
    }

    public function testPrepareDataWithMissingNestedValue(): void
    {
        $rawData = ['user' => ['name' => 'John']];
        $fields = [Field::for('email')->mapFrom('user.profile.email')->default('default@example.com')];

        $prepared = $this->extractor->prepareData($rawData, $fields);

        $this->assertEquals(['email' => 'default@example.com'], $prepared);
    }

    public function testApplyPostprocessing(): void
    {
        $data = ['status' => 'active', 'tags' => ['php', 'testing', 'php']];
        $fields = [
            Field::for('status')->postprocess(fn(mixed $v): string => strtoupper((string)$v)),
            Field::for('tags')->postprocess(fn(array $v): array => array_unique($v))
        ];

        $processed = $this->extractor->applyPostprocessing($data, $fields);

        $this->assertEquals('ACTIVE', $processed['status']);
        $this->assertEquals(['php', 'testing'], array_values($processed['tags']));
    }

    public function testPostprocessingSkipsNonExistentFields(): void
    {
        $data = ['title' => 'Test'];
        $fields = [
            Field::for('title')->postprocess(fn(mixed $v): string => strtoupper((string)$v)),
            Field::for('description')->postprocess(fn(mixed $v): string => trim((string)$v))
        ];

        $processed = $this->extractor->applyPostprocessing($data, $fields);

        $this->assertEquals(['title' => 'TEST'], $processed);
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed> $queryParams
     */
    private function createMockRequest(
        string $method,
        ?array $parsedBody = null,
        array $queryParams = []
    ): ServerRequestInterface {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParsedBody')->willReturn($parsedBody);
        $request->method('getQueryParams')->willReturn($queryParams);

        return $request;
    }
}