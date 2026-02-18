<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\RequestHandler;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RequestHandlerTest extends TestCase
{
    private RequestHandler $handler;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->handler = new RequestHandler($this->validator);
    }

    private function createHandler(bool $autoTrim = true): RequestHandler
    {
        return new RequestHandler($this->validator, $autoTrim);
    }

    public function testHandleExtractsAndValidatesData(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test Item', 'price' => '10.50']);
        $request->method('getQueryParams')->willReturn([]);

        // name and price are required (no ? and no default), so 'required' is auto-added
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Test Item', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test Item', $dto->name);
        $this->assertEquals(10.5, $dto->price);
        $this->assertTrue(isset($dto->name));
        $this->assertTrue(isset($dto->price));
    }

    public function testHandleThrowsValidationException(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(['name' => ['Required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handle(TestRequest::class, $request);
    }

    public function testHandleWithMissingOptionalField(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertFalse(isset($dto->description));
    }

    public function testHandleWithDefaultValue(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals(1, $dto->page);
    }

    public function testHandleWithNestedMapping(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'user' => ['id' => 123],
            'name' => 'Test'
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals(123, $dto->userId);
    }

    public function testHandleWithPreAndPostProcessing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => '  Test  ',
            'slug' => 'Test Title'
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(ProcessingRequest::class, $request);

        $this->assertEquals('Test', $dto->name); // Trimmed
        $this->assertEquals('test-title', $dto->slug); // Slugified
    }

    public function testHandleWithGetRequest(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn(['name' => 'Test', 'price' => '20']);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals(20.0, $dto->price);
    }

    public function testHandleWithCustomCaster(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['id' => '5']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(CustomCasterRequest::class, $request);

        $this->assertEquals(10, $dto->id); // 5 * 2 = 10
    }

    public function testHandleWithPreProcessorInterface(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PreProcessorRequest::class, $request);

        $this->assertEquals('pre_test', $dto->value);
    }

    public function testHandleWithPostProcessorInterface(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PostProcessorRequest::class, $request);

        $this->assertEquals('test_post', $dto->value);
    }

    public function testHandleWithEmptyFieldHavingDefault(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test', 'page' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals(1, $dto->page); // Default value
    }

    public function testHandleWithEmptyFieldWithoutDefault(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test', 'description' => '']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertNull($dto->description);
    }

    public function testHandleWithCasterAsProcessor(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(CasterAsProcessorRequest::class, $request);

        $this->assertEquals('casted_test', $dto->value);
    }

    public function testHandleWithUnknownProcessor(): void
    {
        // Unknown processor now throws ConfigurationException at metadata building time
        $this->expectException(\Solo\RequestHandler\Exceptions\ConfigurationException::class);
        $this->expectExceptionMessage("has invalid preProcess 'nonExistentHandler'");

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->handler->handle(UnknownProcessorRequest::class, $request);
    }

    public function testGroupReturnsFieldsWithSameGroup(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn([
            'search' => 'test',
            'deleted' => 'only',
            'page' => '2',
            'per_page' => '10'
        ]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GroupedRequest::class, $request);

        $criteria = $dto->group('criteria');
        $this->assertEquals(['search' => 'test', 'deleted' => 'only'], $criteria);
        $this->assertArrayNotHasKey('page', $criteria);
        $this->assertArrayNotHasKey('per_page', $criteria);
    }

    public function testGroupReturnsEmptyArrayWhenNoFieldsMatch(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn(['page' => '1']);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GroupedRequest::class, $request);

        // After refactoring: properties with default null are now always initialized
        // This is correct behavior - fixes the "must not be accessed before initialization" bug
        $this->assertEquals(['search' => null, 'deleted' => null], $dto->group('criteria'));
    }

    public function testFieldAttributeIsOptional(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'John', 'email' => 'john@example.com']);
        $request->method('getQueryParams')->willReturn([]);

        // Only properties with validation rules will be validated
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'John'],
                ['name' => 'required|string']
            )
            ->willReturn([]);

        $dto = $this->handler->handle(NoAttributeRequest::class, $request);

        $this->assertEquals('John', $dto->name);
        $this->assertEquals('john@example.com', $dto->email);
        $this->assertEquals(18, $dto->age); // Default value
    }

    public function testRequiredFieldMissing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]); // Missing required field
        $request->method('getQueryParams')->willReturn([]);

        // name is required (has 'required' in rules) but missing
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => null],
                ['name' => 'required|string']
            )
            ->willReturn(['name' => ['The name field is required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handle(NoAttributeRequest::class, $request);
    }

    public function testOptionalFieldWithoutRulesHasNoValidation(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        // Optional field with no rules - no validation called
        $this->validator->expects($this->never())->method('validate');

        $dto = $this->handler->handle(OptionalNoRulesRequest::class, $request);

        $this->assertEquals('test', $dto->value);
    }

    public function testNonCasterClassInCastIsIgnored(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => 'test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(InvalidCasterRequest::class, $request);

        // Value unchanged because class doesn't implement CasterInterface
        $this->assertEquals('test', $dto->value);
    }

    public function testExplicitBuiltInCast(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['value' => '42']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(ExplicitBuiltInCastRequest::class, $request);

        $this->assertSame(42, $dto->value);
    }

    public function testNonBuiltInTypeWithoutCast(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['data' => ['key' => 'value']]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(NonBuiltInTypeRequest::class, $request);

        // Array returned as-is (stdClass is not a built-in type for casting)
        $this->assertEquals(['key' => 'value'], $dto->data);
    }

    public function testPopulateInstanceIgnoresNonExistentProperties(): void
    {
        // Use reflection to call populateInstance with invalid property name
        $handlerReflection = new \ReflectionClass($this->handler);
        $method = $handlerReflection->getMethod('populateInstance');

        $classReflection = new \ReflectionClass(TestRequest::class);
        $instance = $classReflection->newInstanceWithoutConstructor();

        // Call with data containing non-existent property
        $dto = $method->invoke($this->handler, $instance, $classReflection, [
            'name' => 'Test',
            'nonExistent' => 'ignored', // This property doesn't exist
        ]);

        $this->assertEquals('Test', $dto->name);
        $this->assertFalse(property_exists($dto, 'nonExistent'));
    }

    public function testPopulateInstanceSkipsNullForNonNullableTypes(): void
    {
        // Test the runtime protection that prevents null assignment to non-nullable types
        $handlerReflection = new \ReflectionClass($this->handler);
        $method = $handlerReflection->getMethod('populateInstance');

        $classReflection = new \ReflectionClass(NonNullableRequest::class);
        $instance = $classReflection->newInstanceWithoutConstructor();

        // Attempt to set null to a non-nullable string property
        $dto = $method->invoke($this->handler, $instance, $classReflection, [
            'id' => null,  // This should be skipped (id is non-nullable int)
            'name' => 'Test',
        ]);

        $this->assertEquals('Test', $dto->name);
        $this->assertFalse(isset($dto->id)); // Should remain uninitialized
    }

    public function testAutoTrimEnabledByDefault(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => '  Test  ', 'price' => '10.50']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Test', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $this->handler->handle(TestRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
    }

    public function testAutoTrimCanBeDisabled(): void
    {
        $handler = $this->createHandler(autoTrim: false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => '  Test  ', 'price' => '10.50']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => '  Test  ', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $handler->handle(TestRequest::class, $request);

        $this->assertEquals('  Test  ', $dto->name);
    }

    public function testAutoTrimOnlyAffectsStrings(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => '  Test  ',
            'tags' => ['  tag1  ', '  tag2  ']
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(AutoTrimArrayRequest::class, $request);

        // String is trimmed
        $this->assertEquals('Test', $dto->name);
        // Array values are NOT trimmed (autoTrim only affects string values at top level)
        $this->assertEquals(['  tag1  ', '  tag2  '], $dto->tags);
    }

    public function testGeneratorFieldIsAutoGenerated(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test Product']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GeneratorRequest::class, $request);

        $this->assertEquals('Test Product', $dto->name);
        $this->assertTrue(isset($dto->id));
        // Validate UUID v4 format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $dto->id
        );
    }

    public function testGeneratorWithOptions(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(GeneratorWithOptionsRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals('users_123', $dto->id);
    }

    public function testRouteParamsPlaceholderReplacement(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('PUT');
        $request->method('getParsedBody')->willReturn(['name' => 'Updated Name']);
        $request->method('getQueryParams')->willReturn([]);

        // Verify that {id} placeholder is replaced with actual route param value
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Updated Name'],
                ['name' => 'required|string|unique:products,name,123'] // {id} replaced with 123
            )
            ->willReturn([]);

        $dto = $this->handler->handle(
            RouteParamsRequest::class,
            $request,
            ['id' => 123]
        );

        $this->assertEquals('Updated Name', $dto->name);
    }

    public function testPostProcessorSkipsAutoCast(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        // Send JSON string - without the fix, auto-cast would convert it to ['["a","b"]'] (single value wrapped)
        $request->method('getParsedBody')->willReturn(['tags' => '["a","b"]']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PostProcessorSkipsCastRequest::class, $request);

        // PostProcessor receives raw string and decodes it properly
        $this->assertEquals(['a', 'b'], $dto->tags);
    }

    public function testRegisterAllowsDependencyInjection(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Test']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        // Register generator with "injected" dependency
        $generatorWithDep = new TestGeneratorWithDependency('injected_prefix');
        $this->handler->register(TestGeneratorWithDependency::class, $generatorWithDep);

        $dto = $this->handler->handle(RegisteredGeneratorRequest::class, $request);

        $this->assertEquals('Test', $dto->name);
        $this->assertEquals('injected_prefix_generated', $dto->id);
    }

    public function testRegisterReturnsSelfForChaining(): void
    {
        $result = $this->handler
            ->register(TestPreProcessor::class, new TestPreProcessor())
            ->register(TestPostProcessor::class, new TestPostProcessor());

        $this->assertSame($this->handler, $result);
    }

    public function testPostProcessorReceivesConfig(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['amount' => '99.99']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PostProcessConfigRequest::class, $request);

        $this->assertEquals('$99.99', $dto->amount);
    }

    public function testPostProcessorWorksWithoutConfig(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['amount' => '99.99']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(PostProcessNoConfigRequest::class, $request);

        $this->assertEquals('99.99', $dto->amount);
    }

    public function testHandleWithItems(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [
                ['product' => 'Widget', 'quantity' => '3'],
                ['product' => 'Gadget', 'quantity' => '1'],
            ],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(OrderRequest::class, $request);

        $this->assertEquals('Order #1', $dto->name);
        $this->assertIsArray($dto->items);
        $this->assertCount(2, $dto->items);
        $this->assertEquals('Widget', $dto->items[0]['product']);
        $this->assertSame(3, $dto->items[0]['quantity']);
        $this->assertEquals('Gadget', $dto->items[1]['product']);
        $this->assertSame(1, $dto->items[1]['quantity']);
    }

    public function testHandleWithItemsValidationErrors(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [
                ['product' => '', 'quantity' => '3'],
                ['product' => 'Gadget', 'quantity' => ''],
            ],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        // First call validates OrderRequest (name) - passes
        // Second and third calls validate OrderItemRequest items - fail
        $this->validator->method('validate')
            ->willReturnCallback(function (array $data, array $rules) {
                if (isset($rules['product']) && ($data['product'] === '' || $data['product'] === null)) {
                    return ['product' => ['Product is required']];
                }
                if (isset($rules['quantity']) && ($data['quantity'] === '' || $data['quantity'] === null)) {
                    return ['quantity' => ['Quantity is required']];
                }
                return [];
            });

        try {
            $this->handler->handle(OrderRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items.0.product', $errors);
            $this->assertArrayHasKey('items.1.quantity', $errors);
        }
    }

    public function testHandleWithItemsNonArrayItem(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [
                'not-an-array',
                ['product' => 'Widget', 'quantity' => '1'],
            ],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        try {
            $this->handler->handle(OrderRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items.0', $errors);
            $this->assertEquals([['rule' => 'array']], $errors['items.0']);
        }
    }

    public function testHandleWithNullItems(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn(['name' => 'Order #1']);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(OrderRequest::class, $request);

        $this->assertEquals('Order #1', $dto->name);
        $this->assertNull($dto->items);
    }

    public function testHandleWithEmptyItems(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(OrderRequest::class, $request);

        $this->assertEquals('Order #1', $dto->name);
        $this->assertIsArray($dto->items);
        $this->assertEmpty($dto->items);
    }

    public function testHandleArrayMethod(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(OrderItemRequest::class, [
            'product' => 'Widget',
            'quantity' => '5',
        ]);

        $this->assertEquals('Widget', $dto->product);
        $this->assertSame(5, $dto->quantity);
    }

    public function testItemsSkipsCasting(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [
                ['product' => 'Widget', 'quantity' => '2'],
            ],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        $this->validator->method('validate')->willReturn([]);

        // If casting was applied to the items array, BuiltInCaster would
        // try to process it. Items should bypass casting entirely and
        // delegate to processRawData for each item individually.
        $dto = $this->handler->handle(OrderRequest::class, $request);

        $this->assertNotNull($dto->items);
        $this->assertCount(1, $dto->items);
        $this->assertEquals('Widget', $dto->items[0]['product']);
        $this->assertSame(2, $dto->items[0]['quantity']);
    }

    public function testItemsWithRouteParams(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([
            'name' => 'Order #1',
            'items' => [
                ['product' => 'Widget', 'quantity' => '1'],
            ],
        ]);
        $request->method('getQueryParams')->willReturn([]);

        // Verify route params are passed through to nested items
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handle(
            OrderRequest::class,
            $request,
            ['id' => 42]
        );

        $this->assertNotNull($dto->items);
        $this->assertCount(1, $dto->items);
    }

}

final class TestRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;

    #[Field(rules: 'required|numeric')]
    public float $price;

    public ?string $description = null;

    public int $page = 1;

    #[Field(mapFrom: 'user.id')]
    public ?int $userId = null;
}

final class ProcessingRequest extends Request
{
    #[Field(preProcess: 'trim')]
    public string $name;

    #[Field(postProcess: 'slugify')]
    public string $slug;

    public static function slugify(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
}

final class CustomCasterRequest extends Request
{
    #[Field(cast: CustomCaster::class)]
    public int $id;
}

final class PreProcessorRequest extends Request
{
    #[Field(preProcess: TestPreProcessor::class)]
    public string $value;
}

final class PostProcessorRequest extends Request
{
    #[Field(postProcess: TestPostProcessor::class)]
    public string $value;
}

final class GroupedRequest extends Request
{
    #[Field(group: 'criteria')]
    public ?string $search = null;

    #[Field(rules: 'in:only,with', group: 'criteria')]
    public ?string $deleted = null;

    #[Field(rules: 'min:1')]
    public int $page = 1;

    #[Field(rules: 'min:1|max:100')]
    public int $per_page = 20;
}

final class CustomCaster implements \Solo\RequestHandler\Contracts\CasterInterface
{
    public function cast(mixed $value): int
    {
        return (int) $value * 2;
    }
}

final class TestPreProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value): string
    {
        return 'pre_' . $value;
    }
}

final class TestPostProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value): string
    {
        return $value . '_post';
    }
}

final class TestCasterAsProcessor implements \Solo\RequestHandler\Contracts\CasterInterface
{
    public function cast(mixed $value): string
    {
        return 'casted_' . $value;
    }
}

final class CasterAsProcessorRequest extends Request
{
    #[Field(preProcess: TestCasterAsProcessor::class)]
    public string $value;
}

final class UnknownProcessorRequest extends Request
{
    #[Field(preProcess: 'nonExistentHandler')]
    public string $value;
}

// Test: #[Field] is optional, properties without it still work
final class NoAttributeRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;

    public ?string $email = null;  // No rules - won't be validated
    public int $age = 18;          // No rules - won't be validated
}

final class OptionalNoRulesRequest extends Request
{
    public ?string $value = null;
}

// Class that doesn't implement CasterInterface
final class NotACaster
{
    public function cast(mixed $value): string
    {
        return 'should_not_work';
    }
}

final class InvalidCasterRequest extends Request
{
    #[Field(cast: NotACaster::class)]
    public string $value;
}

final class ExplicitBuiltInCastRequest extends Request
{
    #[Field(cast: 'int')]
    public int $value;
}

final class NonBuiltInTypeRequest extends Request
{
    /** @var mixed */
    public $data;
}

final class NonNullableRequest extends Request
{
    public int $id;
    public string $name;
}

final class AutoTrimArrayRequest extends Request
{
    public string $name;
    /** @var array<string> */
    public array $tags = [];
}

final class GeneratorRequest extends Request
{
    #[Field(generator: TestUuidGenerator::class)]
    public string $id;

    #[Field(rules: 'required|string')]
    public string $name;
}

final class GeneratorWithOptionsRequest extends Request
{
    #[Field(generator: TestIdGenerator::class, generatorOptions: ['table' => 'users', 'id' => 123])]
    public string $id;

    #[Field(rules: 'required|string')]
    public string $name;
}

final class TestUuidGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $uuid = random_bytes(16);
        $uuid[6] = $uuid[6] & "\x0F" | "\x40";
        $uuid[8] = $uuid[8] & "\x3F" | "\x80";

        $hex = bin2hex($uuid);

        return
            substr($hex, 0, 8) . '-' .
            substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' .
            substr($hex, 20, 12);
    }
}

final class TestIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        $table = $options['table'] ?? 'unknown';
        $id = $options['id'] ?? 0;
        return "{$table}_{$id}";
    }
}

final class RouteParamsRequest extends Request
{
    #[Field(rules: 'required|string|unique:products,name,{id}')]
    public string $name;
}

final class PostProcessorSkipsCastRequest extends Request
{
    /** @var array<string>|null */
    #[Field(postProcess: JsonToArrayProcessor::class)]
    public ?array $tags = null;
}

final class JsonToArrayProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    /** @return array<string> */
    public function process(mixed $value): array
    {
        return json_decode($value, true);
    }
}

final class RegisteredGeneratorRequest extends Request
{
    #[Field(generator: TestGeneratorWithDependency::class)]
    public string $id;

    #[Field(rules: 'required|string')]
    public string $name;
}

final class TestGeneratorWithDependency implements GeneratorInterface
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function generate(array $options = []): string
    {
        return $this->prefix . '_generated';
    }
}

final class TestConfigProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    /** @param array<string, mixed> $config */
    public function process(mixed $value, array $config = []): string
    {
        $prefix = $config['prefix'] ?? '';
        return $prefix . $value;
    }
}

final class PostProcessConfigRequest extends Request
{
    #[Field(
        rules: 'required|string',
        postProcess: TestConfigProcessor::class,
        postProcessConfig: ['prefix' => '$']
    )]
    public string $amount;
}

final class PostProcessNoConfigRequest extends Request
{
    #[Field(rules: 'required|string', postProcess: TestConfigProcessor::class)]
    public string $amount;
}

final class OrderItemRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $product;

    #[Field(rules: 'required|integer')]
    public int $quantity;
}

final class OrderRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;

    /** @var array<int, array<string, mixed>>|null */
    #[Field(rules: 'nullable|array', items: OrderItemRequest::class)]
    public ?array $items = null;
}

