<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Attributes\DateFormat;
use Solo\RequestHandler\Attributes\EmptyToNull;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\NoTrim;
use Solo\RequestHandler\Attributes\PostProcess;
use Solo\RequestHandler\Attributes\PreProcess;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\ProcessContext;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\RequestHandler;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;

#[AllowMockObjectsWithoutExpectations]
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

    /**
     * Validator fake: a field whose rules start with `required` fails with
     * `required` when it is missing (or null) in the payload.
     */
    private function requireWhenMissing(): void
    {
        $this->validator->method('validate')->willReturnCallback(
            static function (array $data, array $rules): array {
                $errors = [];
                foreach ($rules as $field => $ruleSet) {
                    if (str_starts_with($ruleSet, 'required') && !isset($data[$field])) {
                        $errors[$field] = [['rule' => 'required']];
                    }
                }
                return $errors;
            }
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     */
    private function makeRequest(
        ?array $body = null,
        array $query = [],
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn($query);
        return $request;
    }

    // ===== basic body / query / array =====

    public function testHandleBodyExtractsAndValidates(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test Item', 'price' => '10.50']);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Test Item', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('Test Item', $dto->name);
        $this->assertSame(10.5, $dto->price);
    }

    public function testHandleBodyThrowsValidationException(): void
    {
        $request = $this->makeRequest(body: ['name' => '']);

        $this->validator->expects($this->once())->method('validate')->willReturn(['name' => ['Required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(BasicRequest::class, $request);
    }

    public function testHandleBodyWithMissingOptionalField(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('Test', $dto->name);
        $this->assertFalse(isset($dto->description));
    }

    public function testHandleBodyWithDefaultValue(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame(1, $dto->page);
    }

    public function testHandleQueryUsesQueryBag(): void
    {
        $request = $this->makeRequest(query: ['name' => 'Test', 'price' => '20']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleQuery(BasicRequest::class, $request);

        $this->assertSame('Test', $dto->name);
        $this->assertSame(20.0, $dto->price);
    }

    public function testHandleArrayMethod(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(OrderItemRequest::class, [
            'product' => 'Widget',
            'quantity' => '5',
        ]);

        $this->assertSame('Widget', $dto->product);
        $this->assertSame(5, $dto->quantity);
    }

    // ===== source attributes =====

    public function testFromRouteResolvesFromRouteBag(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            UpdateProductRequest::class,
            $request,
            route: ['id' => 42],
        );

        $this->assertSame(42, $dto->id);
        $this->assertSame('X', $dto->name);
    }

    public function testFromRouteWithExplicitKey(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            UpdateWithExplicitKeyRequest::class,
            $request,
            route: ['product_id' => 7],
        );

        $this->assertSame(7, $dto->productId);
    }

    public function testFromContextReads(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            ContextRequest::class,
            $request,
            context: ['authUserId' => 17],
        );

        $this->assertSame(17, $dto->createdBy);
    }

    public function testMissingFromRouteWithoutDefaultDoesNotLeakBody(): void
    {
        // Body has key 'id' but property declares FromRoute — body must NOT satisfy it.
        $request = $this->makeRequest(body: ['id' => 999, 'name' => 'X']);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                $this->callback(fn (array $data): bool => array_key_exists('id', $data) && $data['id'] === null),
                $this->anything()
            )
            ->willReturn(['id' => [['rule' => 'required']]]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(UpdateProductRequest::class, $request);
    }

    // ===== regression: extras do NOT leak into nested items =====

    public function testRouteDoesNotLeakIntoNestedItems(): void
    {
        // Outer route param `id` (= 999) must not overwrite each item's own `id` field.
        $request = $this->makeRequest(body: [
            'name' => 'Order #42',
            'items' => [
                ['id' => 1, 'product' => 'Widget', 'quantity' => '2'],
                ['id' => 2, 'product' => 'Gadget', 'quantity' => '3'],
            ],
        ]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            OrderWithIdItemsRequest::class,
            $request,
            route: ['id' => 999],
        );

        $this->assertIsArray($dto->items);
        $this->assertSame(1, $dto->items[0]->id);
        $this->assertSame(2, $dto->items[1]->id);
    }

    // ===== regression: null in route does not silently break body fields =====

    public function testNullRouteDoesNotAffectBodyField(): void
    {
        // Property reads from body — route null for the same key has no effect.
        $request = $this->makeRequest(body: ['name' => 'real']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            BasicRequest::class,
            $request,
            route: ['name' => null],
        );

        $this->assertSame('real', $dto->name);
    }

    // ===== processing pipeline =====

    public function testPreAndPostProcessing(): void
    {
        $request = $this->makeRequest(body: ['name' => '  Test  ', 'slug' => 'Test Title']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(ProcessingRequest::class, $request);

        $this->assertSame('Test', $dto->name);
        $this->assertSame('test-title', $dto->slug);
    }

    public function testCustomCaster(): void
    {
        $request = $this->makeRequest(body: ['id' => '5']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(CustomCasterRequest::class, $request);

        $this->assertSame(10, $dto->id);
    }

    public function testPreProcessorInterface(): void
    {
        $request = $this->makeRequest(body: ['value' => 'test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(PreProcessorRequest::class, $request);

        $this->assertSame('pre_test', $dto->value);
    }

    public function testPostProcessorInterface(): void
    {
        $request = $this->makeRequest(body: ['value' => 'test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(PostProcessorRequest::class, $request);

        $this->assertSame('test_post', $dto->value);
    }

    public function testCasterAsPreProcessor(): void
    {
        $request = $this->makeRequest(body: ['value' => 'test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(CasterAsProcessorRequest::class, $request);

        $this->assertSame('casted_test', $dto->value);
    }

    public function testEmptyFieldHavingDefault(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test', 'page' => '']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('Test', $dto->name);
        $this->assertSame(1, $dto->page);
    }

    public function testEmptyFieldWithoutDefault(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test', 'description' => '']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('', $dto->description);
    }

    public function testExplicitNullForNullableField(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test', 'description' => null]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertNull($dto->description);
    }

    public function testExplicitNullForRequiredField(): void
    {
        $request = $this->makeRequest(body: ['name' => null]);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => null, 'price' => null],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn(['name' => ['The name field is required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(BasicRequest::class, $request);
    }

    // ===== auto-trim =====

    public function testAutoTrimEnabledByDefault(): void
    {
        $request = $this->makeRequest(body: ['name' => '  Test  ', 'price' => '10.50']);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => 'Test', 'price' => '10.50'],
                ['name' => 'required|string', 'price' => 'required|numeric']
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('Test', $dto->name);
    }

    public function testAutoTrimCanBeDisabled(): void
    {
        $handler = $this->createHandler(autoTrim: false);

        $request = $this->makeRequest(body: ['name' => '  Test  ', 'price' => '10.50']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $handler->handleBody(BasicRequest::class, $request);

        $this->assertSame('  Test  ', $dto->name);
    }

    public function testAutoTrimOnlyAffectsStringsNotArrays(): void
    {
        $request = $this->makeRequest(body: ['name' => '  Test  ', 'tags' => ['  tag1  ', '  tag2  ']]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(AutoTrimArrayRequest::class, $request);

        $this->assertSame('Test', $dto->name);
        $this->assertSame(['  tag1  ', '  tag2  '], $dto->tags);
    }

    public function testNoTrimKeepsTheStringAsSent(): void
    {
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['snippet' => "  echo 1;\n", 'title' => 'Hi'], ['snippet' => 'required|string'])
            ->willReturn([]);

        $dto = $this->handler->handleArray(NoTrimRequest::class, ['snippet' => "  echo 1;\n", 'title' => '  Hi  ']);

        $this->assertSame("  echo 1;\n", $dto->snippet);
        $this->assertSame('Hi', $dto->title);
    }

    public function testNoTrimOnAClass(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(NoTrimClassRequest::class, ['a' => ' x ', 'b' => ' y ']);

        $this->assertSame([' x ', ' y '], [$dto->a, $dto->b]);
    }

    public function testNoTrimWithEmptyToNullStillReadsBlankAsNull(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(NoTrimClassRequest::class, ['a' => '   ', 'c' => '   ']);
        $this->assertSame('   ', $dto->a, 'no #[EmptyToNull]: kept as sent');
        $this->assertNull($dto->c, 'blank is still no value');

        $dto = $this->handler->handleArray(NoTrimClassRequest::class, ['c' => ' z ']);
        $this->assertSame(' z ', $dto->c, 'a filled value is not trimmed');
    }

    public function testImpossibleDateIsAnErrorNotTheNextMonth(): void
    {
        try {
            $this->handler->handleArray(TypeDrivenDateTimeRequest::class, ['immutable' => '2024-02-31']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['immutable' => [['rule' => 'date']]], $e->getErrors());
        }

        try {
            $this->handler->handleArray(DateTimeFormatsRequest::class, ['day' => '2024-02-31']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['day' => [['rule' => 'date_format', 'params' => ['Y-m-d']]]], $e->getErrors());
        }
    }

    public function testDateFormatReadsAJsonNumber(): void
    {
        $dto = $this->handler->handleArray(CompactDateRequest::class, ['day' => 20240115]);

        $this->assertSame('2024-01-15', $dto->day?->format('Y-m-d'));
    }

    public function testYearTypedIntoADateFieldIsADateError(): void
    {
        try {
            $this->handler->handleArray(TypeDrivenDateTimeRequest::class, ['immutable' => '2024']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['immutable' => [['rule' => 'date']]], $e->getErrors());
        }
    }

    // ===== union types =====

    public function testUnionKeepsAValueOfAMemberType(): void
    {
        $dto = $this->handler->handleArray(UnionTypesRequest::class, [
            'amount' => 5,
            'code'   => '007',
            'tags'   => ['a'],
        ]);

        $this->assertSame(5, $dto->amount);
        $this->assertSame('007', $dto->code, 'a string fits int|string as it is');
        $this->assertSame(['a'], $dto->tags);
    }

    public function testUnionConvertsAnotherValueInPhpsOrderWithoutLoss(): void
    {
        $dto = $this->handler->handleArray(UnionTypesRequest::class, [
            'amount' => '5',
            'ratio'  => '2.5',
            'code'   => 2.0,
            'flag'   => 'yes',
            'tags'   => 7,
        ]);

        $this->assertSame(5, $dto->amount, 'int before float');
        $this->assertSame(2.5, $dto->ratio);
        $this->assertSame(2, $dto->code, 'a whole float becomes the int member');
        $this->assertTrue($dto->flag);
        $this->assertSame('7', $dto->tags);
    }

    public function testValueThatFitsNoUnionMemberIsAnError(): void
    {
        // 3.x: a TypeError while populating the DTO — a 500.
        try {
            $this->handler->handleArray(UnionTypesRequest::class, [
                'amount' => 'abc',
                'ratio'  => [1],
                'code'   => ['x'],
                'flag'   => 'maybe',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'amount' => [['rule' => 'numeric']],
                'ratio'  => [['rule' => 'numeric']],
                'code'   => [['rule' => 'string']],
                'flag'   => [['rule' => 'integer']],
            ], $e->getErrors());
        }
    }

    public function testStandaloneTrueAcceptsOnlyTrueForms(): void
    {
        foreach (['yes', '1', true, 'on'] as $input) {
            $this->assertSame(
                ['accepted' => true, 'nickname' => false],
                $this->handler->handleArray(TermsRequest::class, ['accepted' => $input])->toArray(),
                var_export($input, true)
            );
        }

        // 3.x: a TypeError — the value is a boolean, just not `true`.
        foreach (['0', false, 'maybe'] as $input) {
            try {
                $this->handler->handleArray(TermsRequest::class, ['accepted' => $input]);
                $this->fail('Expected ValidationException for ' . var_export($input, true));
            } catch (ValidationException $e) {
                $this->assertSame(['accepted' => [['rule' => 'boolean']]], $e->getErrors());
            }
        }
    }

    public function testFalseMemberKeepsItsLiteral(): void
    {
        $this->assertFalse($this->handler->handleArray(TermsRequest::class, ['nickname' => false])->nickname);
        $this->assertSame('x', $this->handler->handleArray(TermsRequest::class, ['nickname' => 'x'])->nickname);

        try {
            $this->handler->handleArray(TermsRequest::class, ['nickname' => true]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['nickname' => [['rule' => 'string']]], $e->getErrors());
        }
    }

    public function testIterableIsCastLikeAnArray(): void
    {
        $cases = [
            'a,b'       => ['a', 'b'],
            '["x","y"]' => ['x', 'y'],
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->handler->handleArray(IterableRequest::class, ['tags' => $input])->tags);
        }

        $dto = $this->handler->handleArray(IterableRequest::class, ['tags' => ['k' => 'v']]);
        $this->assertSame(['k' => 'v'], $dto->tags);
    }

    public function testIterableFromContextKeepsItsItems(): void
    {
        $feed = (static function (): \Generator {
            yield 'a';
            yield 'b';
        })();

        $dto = $this->handler->handleArray(IterableRequest::class, [], context: ['feed' => $feed]);

        $this->assertSame(['a', 'b'], $dto->feed, 'a generator is not turned into []');
    }

    public function testUnionWithOnlyArrayAmongInputTypesReportsArray(): void
    {
        $dto = $this->handler->handleArray(IterableRequest::class, ['data' => ['a']]);
        $this->assertSame(['a'], $dto->data);

        try {
            $this->handler->handleArray(IterableRequest::class, ['data' => 'x']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['data' => [['rule' => 'array']]], $e->getErrors());
        }
    }

    public function testEmptyStringForANumericUnionMeansNoValue(): void
    {
        $dto = $this->handler->handleArray(UnionTypesRequest::class, ['amount' => '']);

        $this->assertSame(0, $dto->amount);
    }

    // ===== application-supplied values =====

    public function testContextValueOfWrongTypeIsAProgrammingError(): void
    {
        // Review #5: not a 422 for data the client never sent.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'authUserId'");
        $this->handler->handleBody(
            ContextRequest::class,
            $this->makeRequest(body: ['name' => 'X']),
            context: ['authUserId' => [17]],
        );
    }

    public function testGeneratedValueOfWrongTypeIsAProgrammingError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ArrayGenerator');
        $this->handler->handleArray(BrokenGeneratorRequest::class, []);
    }

    public function testContextValuesGetTheSameLosslessCasts(): void
    {
        $dto = $this->handler->handleArray(
            TrustedScalarsRequest::class,
            [],
            context: ['count' => '42', 'ratio' => 2, 'flag' => 'yes', 'label' => 7],
        );

        $this->assertSame(42, $dto->count);
        $this->assertSame(2.0, $dto->ratio);
        $this->assertTrue($dto->flag);
        $this->assertSame('7', $dto->label);
    }

    #[DataProvider('looseContextValueProvider')]
    public function testContextValueThatWouldLoseInformationThrows(string $key, mixed $value, string $rule): void
    {
        // 3.x coerced these silently; 4.0 holds the application to the declared type.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value '{$key}' does not fit");
        $this->expectExceptionMessage("({$rule})");
        $this->handler->handleArray(TrustedScalarsRequest::class, [], context: [$key => $value]);
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    public static function looseContextValueProvider(): array
    {
        return [
            'fraction for an int' => ['count', 1.5, 'integer'],
            'boolean for an int'  => ['count', true, 'integer'],
            '2 for a bool'        => ['flag', 2, 'boolean'],
            'boolean for string'  => ['label', false, 'string'],
        ];
    }

    public function testClassLevelInputAttributesLeaveContextValuesAlone(): void
    {
        $dto = $this->handler->handleArray(
            BlankClientInputRequest::class,
            ['note' => '   '],
            context: ['tenant' => ''],
        );

        $this->assertNull($dto->note);
        $this->assertSame('', $dto->tenant, 'a context value is not client input');
    }

    public function testAutoTrimLeavesContextValuesAlone(): void
    {
        $dto = $this->handler->handleArray(
            BlankClientInputRequest::class,
            ['note' => '  n  '],
            context: ['tenant' => ' t '],
        );
        $this->assertSame('n', $dto->note);
        $this->assertSame(' t ', $dto->tenant);

        // Class-level #[NoTrim] used to hand context values back to auto-trim.
        $dto = $this->handler->handleArray(
            UntrimmedClassRequest::class,
            ['note' => ' n '],
            context: ['token' => ' t '],
        );
        $this->assertSame(' n ', $dto->note);
        $this->assertSame(' t ', $dto->token);
    }

    public function testNullContextValueForARequiredPropertyThrows(): void
    {
        // 3.x left the property uninitialised without a word.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'tenant' is empty, but");
        $this->handler->handleArray(BlankClientInputRequest::class, [], context: ['tenant' => null]);
    }

    public function testGeneratorReturningNullForARequiredPropertyThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Value generated by');
        $this->handler->handleArray(NullGeneratedRequest::class, []);
    }

    public function testClassTypedContextValueOfAnotherTypeThrows(): void
    {
        // Not built from the array: a TypeError before this check.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'address' is array, which does not fit");
        $this->handler->handleArray(ContextAddressRequest::class, [], context: ['address' => ['city' => 'Kyiv']]);
    }

    public function testGeneratedObjectOfAnotherClassThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('is stdClass, which does not fit');
        $this->handler->handleArray(WrongObjectGeneratedRequest::class, []);
    }

    public function testContextItemsAreTheDtosTheApplicationBuilt(): void
    {
        $line = new LineItemRequest();
        $line->sku = 'A-1';

        $dto = $this->handler->handleArray(ContextLinesRequest::class, [], context: ['lines' => [$line]]);

        $this->assertSame([$line], $dto->lines, 'kept, not rebuilt');
        $this->assertSame(['lines' => [['sku' => 'A-1', 'qty' => 1]]], $dto->toArray());
    }

    public function testContextItemsOfAnotherTypeThrow(): void
    {
        // 3.x built DTOs from the arrays; the application passes built ones now.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'lines' holds array at key 0, but");
        $this->handler->handleArray(ContextLinesRequest::class, [], context: ['lines' => [['sku' => 'A-1']]]);
    }

    public function testRequestTypedContextValueIsKeptAsPassed(): void
    {
        $address = new AddressRequest();
        $address->city = 'Kyiv';

        $dto = $this->handler->handleArray(ContextAddressRequest::class, [], context: ['address' => $address]);

        $this->assertSame($address, $dto->address);
    }

    public function testContextValueThatFitsNoUnionMemberThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'limit' does not fit");
        $this->handler->handleArray(ContextUnionRequest::class, [], context: ['limit' => 'many']);
    }

    // ===== #[EmptyToNull] =====

    public function testBlankStringBecomesNullForTheValidatorAndTheProperty(): void
    {
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['endDate' => null, 'title' => ''], ['endDate' => 'nullable|date', 'title' => 'nullable|string'])
            ->willReturn([]);

        $dto = $this->handler->handleArray(BlankFieldsRequest::class, ['endDate' => '  ', 'title' => '']);

        $this->assertNull($dto->endDate);
        $this->assertSame('', $dto->title, 'without the attribute "" is kept');
    }

    public function testBlankStringFallsBackToTheDefaultOrIsRequired(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(BlankFieldsRequest::class, ['sort' => '']);
        $this->assertSame('id', $dto->sort);

        try {
            $this->handler->handleArray(BlankFieldsRequest::class, ['code' => '']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['code' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    public function testFilledValueIsKeptAndPreProcessorSkipsBlank(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(BlankFieldsRequest::class, ['slug' => '  Hello  ']);
        $this->assertSame('hello', $dto->slug);

        $dto = $this->handler->handleArray(BlankFieldsRequest::class, ['slug' => '']);
        $this->assertNull($dto->slug);
    }

    public function testWhitespaceOnlyIsBlankEvenWithoutAutoTrim(): void
    {
        $this->validator->method('validate')->willReturn([]);
        $handler = $this->createHandler(autoTrim: false);

        $dto = $handler->handleArray(BlankFieldsRequest::class, ['endDate' => " \t", 'title' => ' x ']);

        $this->assertNull($dto->endDate);
        $this->assertSame(' x ', $dto->title);
    }

    public function testEmptyArrayIsNotBlank(): void
    {
        $this->validator->method('validate')->willReturn([]);

        try {
            $this->handler->handleArray(BlankFieldsRequest::class, ['endDate' => []]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['endDate' => [['rule' => 'string']]], $e->getErrors());
        }
    }

    public function testClassLevelEmptyToNull(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(BlankClassRequest::class, ['note' => '', 'tags' => '']);

        $this->assertNull($dto->note);
        $this->assertNull($dto->tags);
    }

    // ===== provided() =====

    public function testProvidedListsWhatTheRequestSupplied(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(
            PatchProductRequest::class,
            ['name' => 'Desk', 'note' => null, 'stock' => ''],
            route: ['id' => '5'],
            context: ['userId' => 7],
        );

        // Declaration order; an explicit null or "" counts, a default does not.
        $this->assertSame(['id', 'name', 'note', 'stock', 'updatedBy', 'stamp'], $dto->provided());
        $this->assertTrue($dto->isProvided('note'));
        $this->assertFalse($dto->isProvided('price'));
        $this->assertTrue($dto->has('price'), 'has() still counts the default');
        $this->assertFalse($dto->isProvided('unknown'));

        $this->assertSame(
            ['id' => 5, 'name' => 'Desk', 'note' => null, 'stock' => 0, 'updatedBy' => 7, 'stamp' => 'generated'],
            $dto->toArray(onlyProvided: true)
        );
        $this->assertArrayHasKey('price', $dto->toArray());
    }

    public function testOnlyProvidedReachesNestedDtosAndItems(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(PatchOrderRequest::class, [
            'address' => ['city' => 'Kyiv'],
            'lines'   => [['qty' => '2'], ['sku' => 'A-1']],
        ]);

        $this->assertSame(['address', 'lines'], $dto->provided());
        $this->assertSame(['city'], $dto->address?->provided());
        $this->assertSame(
            ['address' => ['city' => 'Kyiv'], 'lines' => [['qty' => 2], ['sku' => 'A-1']]],
            $dto->toArray(onlyProvided: true)
        );
    }

    public function testAPublicPropertyNamedProvidedDoesNotClash(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(ProvidedNameClashRequest::class, ['provided' => 'yes']);

        $this->assertSame('yes', $dto->provided);
        $this->assertSame(['provided'], $dto->provided());
        $this->assertSame(['provided' => 'yes'], $dto->toArray(onlyProvided: true));
    }

    public function testProvidedIsNotPartOfTheDtosOwnProperties(): void
    {
        $dto = $this->handler->handleArray(PatchAddressRequest::class, ['city' => 'Kyiv']);

        $this->assertSame(['city' => 'Kyiv', 'zip' => null], (array) $dto);
        $this->assertSame(['city'], $dto->provided());

        // The side table does not keep a DTO alive.
        $reference = \WeakReference::create($dto);
        unset($dto);
        $this->assertNull($reference->get());
    }

    public function testDtoBuiltByHandHasNothingProvided(): void
    {
        $dto = new PatchAddressRequest();
        $dto->city = 'Kyiv';

        $this->assertSame([], $dto->provided());
        $this->assertSame([], $dto->toArray(onlyProvided: true));
        $this->assertSame(['city' => 'Kyiv', 'zip' => null], $dto->toArray());
    }

    // ===== generator =====

    public function testGeneratorFieldIsAutoGenerated(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test Product']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(GeneratorRequest::class, $request);

        $this->assertSame('Test Product', $dto->name);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $dto->id
        );
    }

    public function testGeneratorWithOptions(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(GeneratorWithOptionsRequest::class, $request);

        $this->assertSame('users_123', $dto->id);
    }

    public function testRegisterAllowsDependencyInjection(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Test']);
        $this->validator->method('validate')->willReturn([]);

        $this->handler->register(
            TestGeneratorWithDependency::class,
            new TestGeneratorWithDependency('injected_prefix')
        );

        $dto = $this->handler->handleBody(RegisteredGeneratorRequest::class, $request);

        $this->assertSame('injected_prefix_generated', $dto->id);
    }

    public function testContainerProvidesHandlersWithDependencies(): void
    {
        $this->validator->method('validate')->willReturn([]);
        $container = new ArrayContainer([
            TestGeneratorWithDependency::class => new TestGeneratorWithDependency('from_container'),
        ]);
        $handler = new RequestHandler($this->validator, container: $container);

        $dto = $handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'Test']);
        $this->assertSame('from_container_generated', $dto->id);
        $this->assertSame(1, $container->gets);

        // A registered instance takes precedence over the container.
        $other = new RequestHandler($this->validator, container: $container);
        $other->register(TestGeneratorWithDependency::class, new TestGeneratorWithDependency('registered'));
        $dto = $other->handleArray(RegisteredGeneratorRequest::class, ['name' => 'Test']);
        $this->assertSame('registered_generated', $dto->id);
        $this->assertSame(1, $container->gets, 'the container was not asked');
    }

    public function testContainerEntryIsFetchedOnEveryUse(): void
    {
        // The handler may outlive a request (a container singleton under a
        // long-running server): the container, not the handler, decides
        // whether an entry is shared, built per call or request-scoped.
        $this->validator->method('validate')->willReturn([]);
        $built = 0;
        $container = new ArrayContainer([
            TestGeneratorWithDependency::class => static function () use (&$built): TestGeneratorWithDependency {
                return new TestGeneratorWithDependency('call' . ++$built);
            },
        ]);
        $handler = new RequestHandler($this->validator, container: $container);

        $first = $handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'A']);
        $second = $handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'B']);

        $this->assertSame('call1_generated', $first->id);
        $this->assertSame('call2_generated', $second->id);
    }

    public function testClassesTheContainerLacksAreBuiltWithoutArguments(): void
    {
        $this->validator->method('validate')->willReturn([]);
        $handler = new RequestHandler($this->validator, container: new ArrayContainer([]));

        $dto = $handler->handleArray(GeneratorWithOptionsRequest::class, ['name' => 'Test']);

        $this->assertSame('users_123', $dto->id);
    }

    public function testContainerEntryOfTheWrongTypeIsAConfigurationError(): void
    {
        $this->validator->method('validate')->willReturn([]);
        $handler = new RequestHandler(
            $this->validator,
            container: new ArrayContainer([TestGeneratorWithDependency::class => new \stdClass()]),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is a stdClass, not an instance of');
        $handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'Test']);
    }

    public function testProcessorReceivesContext(): void
    {
        $request = $this->makeRequest(body: ['slug' => 'test-slug']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(
            ContextProcessorRequest::class,
            $request,
            route: ['id' => 42],
            context: ['tenant' => 'acme'],
        );

        $this->assertSame('test-slug-42-acme', $dto->slug);
    }

    // ===== post-processor skips auto-cast =====

    public function testPostProcessorSkipsAutoCast(): void
    {
        $request = $this->makeRequest(body: ['tags' => '["a","b"]']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(PostProcessorSkipsCastRequest::class, $request);

        $this->assertSame(['a', 'b'], $dto->tags);
    }

    public function testPostProcessorReceivesConfig(): void
    {
        $request = $this->makeRequest(body: ['amount' => '99.99']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(PostProcessConfigRequest::class, $request);

        $this->assertSame('$99.99', $dto->amount);
    }

    public function testPostProcessorWorksWithoutConfig(): void
    {
        $request = $this->makeRequest(body: ['amount' => '99.99']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(PostProcessNoConfigRequest::class, $request);

        $this->assertSame('99.99', $dto->amount);
    }

    // ===== items =====

    public function testItemsValid(): void
    {
        $request = $this->makeRequest(body: [
            'name' => 'Order #1',
            'items' => [
                ['product' => 'Widget', 'quantity' => '3'],
                ['product' => 'Gadget', 'quantity' => '1'],
            ],
        ]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(OrderRequest::class, $request);

        $this->assertIsArray($dto->items);
        $this->assertCount(2, $dto->items);
        $this->assertSame('Widget', $dto->items[0]->product);
        $this->assertSame(3, $dto->items[0]->quantity);
    }

    public function testItemsValidationErrors(): void
    {
        $request = $this->makeRequest(body: [
            'name' => 'Order #1',
            'items' => [
                ['product' => '', 'quantity' => '3'],
                ['product' => 'Gadget', 'quantity' => ''],
            ],
        ]);

        $this->validator->method('validate')->willReturnCallback(
            function (array $data, array $rules): array {
                if (isset($rules['product']) && ($data['product'] === '' || $data['product'] === null)) {
                    return ['product' => ['Product is required']];
                }
                if (isset($rules['quantity']) && ($data['quantity'] === '' || $data['quantity'] === null)) {
                    return ['quantity' => ['Quantity is required']];
                }
                return [];
            }
        );

        try {
            $this->handler->handleBody(OrderRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items.0.product', $errors);
            $this->assertArrayHasKey('items.1.quantity', $errors);
        }
    }

    public function testItemsNonArrayItemYieldsStructuredError(): void
    {
        $request = $this->makeRequest(body: [
            'name' => 'Order #1',
            'items' => ['not-an-array', ['product' => 'Widget', 'quantity' => '1']],
        ]);
        $this->validator->method('validate')->willReturn([]);

        try {
            $this->handler->handleBody(OrderRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0', $e->getErrors());
            $this->assertSame([['rule' => 'array']], $e->getErrors()['items.0']);
        }
    }

    public function testItemsNonArrayFieldYieldsStructuredErrorNotTypeError(): void
    {
        // Property has #[Items] but no #[Validate('array')] rule; client sends a string.
        // Must produce a clean ValidationException, NOT a TypeError on setValue.
        $request = $this->makeRequest(body: ['items' => 'oops']);
        $this->validator->method('validate')->willReturn([]);

        try {
            $this->handler->handleBody(NoRulesItemsRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
            $this->assertSame([['rule' => 'array']], $e->getErrors()['items']);
        }
    }

    public function testItemsNullStaysNull(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Order #1']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(OrderRequest::class, $request);

        $this->assertNull($dto->items);
    }

    public function testItemsExplicitNullDoesNotThrow(): void
    {
        // F1: explicit `{"items": null}` on a nullable items property must pass
        // through, not raise spurious ValidationException('array').
        $request = $this->makeRequest(body: ['name' => 'Order #1', 'items' => null]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(OrderRequest::class, $request);

        $this->assertNull($dto->items);
    }

    public function testItemsEmptyArrayStaysEmpty(): void
    {
        $request = $this->makeRequest(body: ['name' => 'Order #1', 'items' => []]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(OrderRequest::class, $request);

        $this->assertSame([], $dto->items);
    }

    // ===== groups =====

    public function testGroupReturnsFieldsWithSameGroup(): void
    {
        $request = $this->makeRequest(query: [
            'search' => 'test',
            'deleted' => 'only',
            'page' => '2',
            'per_page' => '10',
        ]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleQuery(GroupedRequest::class, $request);

        $this->assertSame(['search' => 'test', 'deleted' => 'only'], $dto->group('criteria'));
    }

    public function testGroupSkipsNullScalarMembers(): void
    {
        // Pre-2.0 group() emitted ['search' => null, 'deleted' => null]; now nulls are skipped.
        $request = $this->makeRequest(query: ['page' => '1']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleQuery(GroupedRequest::class, $request);

        $this->assertSame([], $dto->group('criteria'));
    }

    public function testGroupUsesMapTo(): void
    {
        $request = $this->makeRequest(query: [
            'position_id' => '5',
            'search' => 'test',
            'page' => '2',
        ]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleQuery(MapToGroupedRequest::class, $request);

        $criteria = $dto->group('criteria');
        $this->assertSame(['positions.id' => 5, 'search' => 'test'], $criteria);
    }

    // ===== passthrough properties (no attributes) =====

    public function testPublicPropertiesWithoutAttributesArePassThrough(): void
    {
        $request = $this->makeRequest(body: ['name' => 'John', 'email' => 'john@example.com']);

        // `email` declares no rules, so none are passed for it — but its value
        // is still part of the payload for cross-field rules to reference.
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['name' => 'John', 'email' => 'john@example.com'], ['name' => 'required|string'])
            ->willReturn([]);

        $dto = $this->handler->handleBody(NoAttributeRequest::class, $request);

        $this->assertSame('John', $dto->name);
        $this->assertSame('john@example.com', $dto->email);
        $this->assertSame(18, $dto->age);
    }

    public function testProtectedPropertyIsIgnoredByHandler(): void
    {
        // Visibility-based opt-out: protected/private properties stay outside the pipeline.
        $request = $this->makeRequest(body: ['name' => 'X', 'internal' => 'evil']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(ProtectedInternalRequest::class, $request);

        $this->assertSame('X', $dto->name);
        $this->assertSame('default-internal', $dto->reveal());
    }

    public function testIgnoredPublicPropertyIsNotPopulatedFromInput(): void
    {
        // #[Ignore] on a public property — handler must not read 'computed' from body,
        // even though the body has a key by that name.
        $request = $this->makeRequest(body: ['name' => 'X', 'computed' => 'attacker-supplied']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(IgnorePublicRequest::class, $request);

        $this->assertSame('X', $dto->name);
        $this->assertSame('controller-default', $dto->computed);
    }

    public function testIgnoredPublicPropertyIsNotValidatedNorInToArray(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X']);
        // Validator should NOT receive 'computed' in the rules map.
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['name' => 'X'], ['name' => 'required|string'])
            ->willReturn([]);

        $dto = $this->handler->handleBody(IgnorePublicRequest::class, $request);

        $this->assertSame(['name' => 'X'], $dto->toArray());
    }

    public function testIgnoredPropertyAssignedByControllerSurvives(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(IgnorePublicRequest::class, $request);
        $dto->computed = 'set-by-controller';

        $this->assertSame('set-by-controller', $dto->computed);
        $this->assertSame(['name' => 'X'], $dto->toArray());
    }

    public function testIgnoreWithCompanionAttributeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[Ignore]');
        $this->handler->handleBody(IgnoreWithValidateConflictRequest::class, $this->makeRequest(body: []));
    }

    // ===== FromBody (nested dot-path) =====

    public function testFromBodyReadsNestedValue(): void
    {
        $request = $this->makeRequest(body: [
            'customer' => ['id' => 42, 'name' => 'Alice'],
            'note'     => 'urgent',
        ]);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['customerId' => 42, 'note' => 'urgent'],
                ['customerId' => 'required|integer', 'note' => 'nullable|string']
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(FromBodyOrderRequest::class, $request);

        $this->assertSame(42, $dto->customerId);
        $this->assertSame('urgent', $dto->note);
    }

    public function testFromBodyDeeplyNestedValue(): void
    {
        $request = $this->makeRequest(body: [
            'shipping' => ['address' => ['zip' => '90210']],
        ]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(FromBodyDeepRequest::class, $request);

        $this->assertSame('90210', $dto->shippingZip);
    }

    public function testFromBodyMissingIntermediateUsesDefault(): void
    {
        // 'customer' key absent entirely — property has default, no validation triggered.
        $request = $this->makeRequest(body: ['note' => 'x']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(FromBodyWithDefaultRequest::class, $request);

        $this->assertSame(0, $dto->customerId);
    }

    public function testFromBodyMissingIntermediateTriggersRequired(): void
    {
        // 'customer' key absent on a required field → validator sees null.
        $request = $this->makeRequest(body: []);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['customerId' => null], ['customerId' => 'required|integer'])
            ->willReturn(['customerId' => [['rule' => 'required']]]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(FromBodyOrderRequest::class, $request);
    }

    public function testFromBodyNonArrayIntermediateBehavesAsMissing(): void
    {
        // 'customer' is a scalar string instead of an array — traversal must
        // stop cleanly and yield "missing", not blow up.
        $request = $this->makeRequest(body: ['customer' => 'not-an-array']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(FromBodyWithDefaultRequest::class, $request);

        $this->assertSame(0, $dto->customerId);
    }

    public function testFromBodySingleSegmentRemap(): void
    {
        // FromBody('display_name') on $name: body['display_name'] feeds $name.
        $request = $this->makeRequest(body: ['display_name' => 'Alice']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(FromBodyFlatRequest::class, $request);

        $this->assertSame('Alice', $dto->name);
    }

    public function testFromBodyCombinedWithFromRouteThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple source attributes');
        $this->handler->handleBody(FromBodyAndRouteConflictRequest::class, $this->makeRequest(body: []));
    }

    public function testFromBodyArrayLeafOnScalarPropertyEmitsScalarError(): void
    {
        // F11: pointing FromBody at a sub-object on a string-typed property
        // produces a clear `rule: scalar` ValidationException with path info,
        // not a misleading 'string' validator error.
        $request = $this->makeRequest(body: ['shipping' => ['address' => ['zip' => '90210']]]);
        $this->validator->method('validate')->willReturn([]);

        try {
            $this->handler->handleBody(FromBodyScalarMismatchRequest::class, $request);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('shipping', $errors);
            $this->assertSame('string', $errors['shipping'][0]['rule']);
        }
    }

    // ===== F3: PSR-7 object body =====

    public function testObjectBodyIsNormalisedToArray(): void
    {
        // Middleware-decoded JSON (`json_decode($json)` without assoc=true)
        // returns stdClass — body normalisation must convert it to array.
        $body = (object)['name' => 'Alice', 'nested' => (object)['key' => 'value']];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn([]);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(NoAttributeRequest::class, $request);

        $this->assertSame('Alice', $dto->name);
    }

    // ===== F5: explicit null/empty values get validated =====

    public function testExplicitEmptyStringIsHandedToValidator(): void
    {
        // F5: `{"email": ""}` on an optional `email` rule must reach the validator,
        // not silently bypass it.
        $request = $this->makeRequest(body: ['email' => '']);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['email' => ''], ['email' => 'nullable|email'])
            ->willReturn(['email' => [['rule' => 'email']]]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(OptionalEmailRequest::class, $request);
    }

    public function testExplicitNullIsHandedToValidator(): void
    {
        $request = $this->makeRequest(body: ['email' => null]);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['email' => null], ['email' => 'nullable|email'])
            ->willReturn([]);

        $dto = $this->handler->handleBody(OptionalEmailRequest::class, $request);

        $this->assertNull($dto->email);
    }

    // ===== conditional presence: required_* fires for absent fields =====

    public function testAbsentFieldWithRequiredIfIsHandedToValidator(): void
    {
        // Regression: `{"company_type":"company"}` with no `name` key must
        // enrol `name` (default null) into validation so `required_if` can
        // fire — previously the default caused the field to be skipped
        // entirely and the request passed with no name at all.
        $request = $this->makeRequest(body: ['company_type' => 'company']);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['company_type' => 'company', 'name' => null],
                [
                    'company_type' => 'in:individual,company',
                    'name'         => 'nullable|string|max:255|required_if:company_type,company',
                ]
            )
            ->willReturn(['name' => [['rule' => 'required_if', 'params' => ['company_type', 'company']]]]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(ConditionalCompanyRequest::class, $request);
    }

    public function testAbsentFieldWithRequiredIfConditionNotMetPasses(): void
    {
        // The handler always enrols the field; deciding whether the condition
        // holds is the validator's job. When it reports no errors, the DTO
        // keeps its declared defaults.
        $request = $this->makeRequest(body: ['company_type' => 'individual']);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['company_type' => 'individual', 'name' => null],
                [
                    'company_type' => 'in:individual,company',
                    'name'         => 'nullable|string|max:255|required_if:company_type,company',
                ]
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(ConditionalCompanyRequest::class, $request);

        $this->assertSame('individual', $dto->company_type);
        $this->assertNull($dto->name);
    }

    public function testAbsentFieldsWithRequiredIfEnrolDefaultsOnEmptyBody(): void
    {
        // Fully-absent body: only the required_* field is enrolled (with its
        // default as the value); plain defaulted fields stay out of validation.
        $request = $this->makeRequest(body: []);

        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['name' => null],
                ['name' => 'nullable|string|max:255|required_if:company_type,company']
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(ConditionalCompanyRequest::class, $request);

        $this->assertSame('individual', $dto->company_type);
        $this->assertNull($dto->name);
        $this->assertSame(1, $dto->page);
    }

    public function testAbsentDefaultedFieldsWithoutPresenceRulesSkipValidation(): void
    {
        // Hot path guard: a field with a default and NO required_* rule must
        // never be enrolled when absent — with nothing enrolled the validator
        // is not invoked at all.
        $request = $this->makeRequest(body: []);

        $this->validator->expects($this->never())->method('validate');

        $dto = $this->handler->handleBody(DefaultsOnlyRequest::class, $request);

        $this->assertSame('individual', $dto->company_type);
        $this->assertSame(1, $dto->page);
    }

    // ===== F8: generator-conflict wins over rule-related checks =====

    public function testGeneratorWithRequiredRuleAndDefaultReportsGeneratorConflict(): void
    {
        // F8: previous order surfaced `requiredWithDefault` first; now Generator
        // conflict wins because that's the actual misconfiguration.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Generator');
        $this->expectExceptionMessage('Validate');
        $this->handler->handleBody(
            GeneratorRequiredDefaultRequest::class,
            $this->makeRequest(body: []),
        );
    }

    // ===== F10: ProcessorRegistry::register type guard =====

    public function testRegisterWithMismatchedInstanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a');
        $this->handler->register(\DateTimeImmutable::class, new \stdClass());
    }

    public function testRequiredFieldMissing(): void
    {
        $request = $this->makeRequest(body: []);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['name' => null], ['name' => 'required|string'])
            ->willReturn(['name' => ['The name field is required']]);

        $this->expectException(ValidationException::class);
        $this->handler->handleBody(NoAttributeRequest::class, $request);
    }

    public function testOptionalFieldWithoutRulesHasNoValidation(): void
    {
        $request = $this->makeRequest(body: ['value' => 'test']);
        $this->validator->expects($this->never())->method('validate');

        $dto = $this->handler->handleBody(OptionalNoRulesRequest::class, $request);

        $this->assertSame('test', $dto->value);
    }

    // ===== exclude =====

    public function testExcludeHidesFromToArrayButRunsPipeline(): void
    {
        $request = $this->makeRequest(body: ['name' => 'X', 'password' => 'secret123']);
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleBody(ExcludeRequest::class, $request);

        $this->assertSame('secret123', $dto->password);
        $this->assertSame(['name' => 'X'], $dto->toArray());
    }

    // ===== configuration exceptions =====

    public function testCastWithClassNameThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("#[Cast('Solo");
        $this->handler->handleBody(CastWithClassRequest::class, $this->makeRequest(body: []));
    }

    public function testCastWithUnknownTypeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("which is neither a built-in type");
        $this->handler->handleBody(UnknownCastRequest::class, $this->makeRequest(body: []));
    }

    public function testCasterMustImplementCasterInterface(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid #[Caster(');
        $this->handler->handleBody(InvalidCasterRequest::class, $this->makeRequest(body: []));
    }

    public function testCastAndCasterConflictThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has both #[Cast] and #[Caster]');
        $this->handler->handleBody(CastAndCasterConflictRequest::class, $this->makeRequest(body: []));
    }

    public function testGeneratorWithValidateThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Generator');
        $this->expectExceptionMessage('Validate');
        $this->handler->handleBody(GeneratorWithValidateRequest::class, $this->makeRequest(body: []));
    }

    public function testGeneratorWithFromRouteThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('FromRoute');
        $this->handler->handleBody(GeneratorWithFromRouteRequest::class, $this->makeRequest(body: []));
    }

    public function testGeneratorWithItemsThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Items');
        $this->handler->handleBody(GeneratorWithItemsRequest::class, $this->makeRequest(body: []));
    }

    public function testItemsWithCastThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Items');
        $this->expectExceptionMessage('Cast');
        $this->handler->handleBody(ItemsWithCastRequest::class, $this->makeRequest(body: []));
    }

    public function testMultipleSourcesThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple source attributes');
        $this->handler->handleBody(MultipleSourcesRequest::class, $this->makeRequest(body: []));
    }

    public function testNonStaticProcessorMethodThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NON-static');
        $this->handler->handleBody(NonStaticProcessorRequest::class, $this->makeRequest(body: ['value' => 'x']));
    }

    public function testUnknownProcessorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("invalid preProcess 'nonExistentHandler'");
        $this->handler->handleBody(UnknownProcessorRequest::class, $this->makeRequest(body: ['value' => 'x']));
    }

    public function testHasRuleDoesNotMatchInsideInList(): void
    {
        // 'in:nullable,active' must NOT trigger nullable rule detection.
        $request = $this->makeRequest(body: ['status' => 'active']);
        $this->validator->method('validate')->willReturn([]);

        // No exception (would throw nullableRuleWithNonNullableType if hasRule false-matched).
        $dto = $this->handler->handleBody(InRuleWithNullableLiteralRequest::class, $request);

        $this->assertSame('active', $dto->status);
    }

    // ===== runtime guard =====

    public function testPopulateInstanceSkipsNullForNonNullableTypes(): void
    {
        $handlerReflection = new \ReflectionClass($this->handler);
        $method = $handlerReflection->getMethod('populateInstance');

        $cacheProperty = $handlerReflection->getProperty('cache');
        /** @var \Solo\RequestHandler\Cache\ReflectionCache $cache */
        $cache = $cacheProperty->getValue($this->handler);
        $metadata = $cache->get(NonNullableRequest::class);
        $instance = $metadata->reflection->newInstanceWithoutConstructor();

        $dto = $method->invoke($this->handler, $instance, $metadata, [
            'id' => null,
            'name' => 'Test',
        ]);

        $this->assertSame('Test', $dto->name);
        $this->assertFalse(isset($dto->id));
        $this->assertSame(['name'], $dto->provided(), 'an uninitialised property is not provided');
    }

    public function testPropertyLeftUninitialisedByAPostProcessorIsNotProvided(): void
    {
        $dto = $this->handler->handleArray(NullingPostProcessRequest::class, ['slug' => 'x', 'page' => 'y']);

        $this->assertFalse($dto->has('slug'));
        $this->assertSame(1, $dto->page, 'null resets a defaulted property');
        $this->assertSame(['page'], $dto->provided());
        $this->assertSame(['page' => 1], $dto->toArray(onlyProvided: true));
    }

    public function testUntypedPropertyPassesValueThroughWithoutCasting(): void
    {
        // Untyped properties have no PHP type to drive the type-based cast,
        // no #[Cast]/#[Caster], so castValue() must return the value verbatim.
        $payload = ['anything' => ['nested' => 'object', 'n' => 1]];

        $dto = $this->handler->handleArray(UntypedPassThroughRequest::class, $payload);

        $this->assertSame(['nested' => 'object', 'n' => 1], $dto->anything);
    }

    // ===== validation payload =====

    public function testValidationPayloadCarriesFieldsWithoutRules(): void
    {
        // Documented contract: the payload holds every field, so a rule like
        // unique:users,email,{id},tenant_id,{tenantId} can resolve {tenantId}
        // although tenantId declares no rules of its own.
        $request = $this->makeRequest(body: ['email' => 'a@b.c']);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(
                ['id' => 123, 'tenantId' => 99, 'email' => 'a@b.c'],
                ['id' => 'required|integer', 'email' => 'required|email']
            )
            ->willReturn([]);

        $dto = $this->handler->handleBody(
            TenantScopedRequest::class,
            $request,
            route: ['id' => 123],
            context: ['tenantId' => 99],
        );

        $this->assertSame(99, $dto->tenantId);
    }

    // ===== error aggregation =====

    public function testParentAndItemErrorsAreReportedTogether(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(OrderWithTwoItemListsRequest::class, [
                'items'  => [['quantity' => '1']],
                'extras' => [['product' => 'ok', 'quantity' => null]],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['name', 'items.0.product', 'extras.0.quantity'], array_keys($e->getErrors()));
        }
    }

    public function testFromBodyScalarErrorDoesNotHideOtherErrors(): void
    {
        // Errors also come back in declaration order: `name` precedes `customerId`.
        $this->validator->method('validate')->willReturn(['name' => [['rule' => 'required']]]);

        try {
            $this->handler->handleArray(FromBodyCustomerRequest::class, ['customer' => ['id' => ['nested' => 1]]]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // Errors are keyed by the input path the client used, not the property name.
            $this->assertSame([
                'name'        => [['rule' => 'required']],
                'customer.id' => [['rule' => 'integer']],
            ], $e->getErrors());
        }
    }

    public function testArrayForScalarPropertyIsValidationError(): void
    {
        // `?page[]=5` used to become page = 1, `?active[]=1` active = true.
        try {
            $this->handler->handleQuery(
                PaginationRequest::class,
                $this->makeRequest(query: ['page' => ['5'], 'active' => ['1']])
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'page'   => [['rule' => 'integer']],
                'active' => [['rule' => 'boolean']],
            ], $e->getErrors());
        }
    }

    // ===== generators run after validation =====

    public function testGeneratorRunsOnlyForValidInput(): void
    {
        CountingGenerator::$calls = 0;
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(CountedGeneratorRequest::class, []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->getErrors());
        }
        $this->assertSame(0, CountingGenerator::$calls);

        $dto = $this->handler->handleArray(CountedGeneratorRequest::class, ['name' => 'x']);

        $this->assertSame(1, CountingGenerator::$calls);
        $this->assertSame(1, $dto->id);
    }

    public function testItemGeneratorsWaitForTheWholeTree(): void
    {
        CountingGenerator::$calls = 0;
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(GeneratedLinesRequest::class, ['lines' => [['sku' => 'A'], ['sku' => 'B']]]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['title'], array_keys($e->getErrors()));
        }

        $this->assertSame(0, CountingGenerator::$calls);
    }

    // ===== processors =====

    public function testPreProcessorIsSkippedForValueOfWrongType(): void
    {
        // A client sending a number where a string is expected gets a
        // validation error, not a TypeError from strtolower(int).
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['email' => 42], ['email' => 'required|email'])
            ->willReturn(['email' => [['rule' => 'email']]]);

        $this->expectException(ValidationException::class);
        $this->handler->handleArray(LowercaseEmailRequest::class, ['email' => 42]);
    }

    public function testPreProcessorStillRunsForAcceptedType(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(LowercaseEmailRequest::class, ['email' => 'A@B.C']);

        $this->assertSame('a@b.c', $dto->email);
    }

    public function testPostProcessorIsSkippedForNull(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $this->assertNull($this->handler->handleArray(UppercaseCodeRequest::class, ['code' => null])->code);
        $this->assertSame('ABC', $this->handler->handleArray(UppercaseCodeRequest::class, ['code' => 'abc'])->code);
    }

    public function testStaticProcessorMayLiveOnSubclass(): void
    {
        $dto = $this->handler->handleArray(ConcreteSlugRequest::class, ['slug' => 'Hello World']);

        $this->assertSame('hello-world', $dto->slug);
    }

    public function testItemsPostProcessorOutputIsResolvedAsItems(): void
    {
        $this->validator->method('validate')->willReturn([]);

        $dto = $this->handler->handleArray(JsonItemsRequest::class, [
            'items' => '[{"product":"Widget","quantity":"2"}]',
        ]);

        $this->assertIsArray($dto->items);
        $this->assertInstanceOf(OrderItemRequest::class, $dto->items[0]);
        $this->assertSame(2, $dto->items[0]->quantity);
    }

    public function testUnregisteredHandlerWithDependenciesFailsClearly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Provide it through the container passed to RequestHandler, or register');
        $this->handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'x']);
    }

    // ===== datetime =====

    public function testDateTimeFormatsAreCast(): void
    {
        $dto = $this->handler->handleArray(DateTimeFormatsRequest::class, [
            'eventTime' => '2024-01-15 10:30:00',
            'day'       => '2024-01-15',
        ]);

        $this->assertSame('2024-01-15 10:30:00', $dto->eventTime->format('Y-m-d H:i:s'));
        $this->assertNotNull($dto->day);
        $this->assertSame('2024-01-15 00:00:00', $dto->day->format('Y-m-d H:i:s'));
    }

    public function testUnparseableDateIsReportedInsteadOfDropped(): void
    {
        try {
            $this->handler->handleArray(DateTimeFormatsRequest::class, ['eventTime' => '15/01/2024', 'day' => 'soon']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'eventTime' => [['rule' => 'date_format', 'params' => ['Y-m-d H:i:s']]],
                'day'       => [['rule' => 'date_format', 'params' => ['Y-m-d']]],
            ], $e->getErrors());
        }
    }

    public function testDateTimePropertiesAreCastByType(): void
    {
        $dto = $this->handler->handleArray(TypeDrivenDateTimeRequest::class, [
            'immutable' => '2024-01-15',
            'mutable'   => '2024-01-15 10:00',
            'either'    => '2024-01-15',
        ]);

        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->immutable);
        $this->assertInstanceOf(\DateTime::class, $dto->mutable);
        // 4.0: DateTimeInterface gets the immutable implementation.
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->either);
    }

    public function testMutableDateTimeFromContextIsConvertedForImmutableProperty(): void
    {
        $dto = $this->handler->handleArray(
            ContextClockRequest::class,
            [],
            context: ['now' => new \DateTime('2024-01-15 10:00:00')],
        );

        $this->assertSame('2024-01-15 10:00:00', $dto->now->format('Y-m-d H:i:s'));
    }

    // ===== enums =====

    public function testBackedEnumPropertiesAreResolved(): void
    {
        $dto = $this->handler->handleArray(EnumRequest::class, [
            'status'   => 'published',
            'priority' => '2',
            'optional' => '',
        ]);

        $this->assertSame(TestStatus::Published, $dto->status);
        $this->assertSame(TestPriority::High, $dto->priority);
        $this->assertNull($dto->optional);
    }

    public function testUnknownEnumValueIsValidationError(): void
    {
        try {
            $this->handler->handleArray(EnumRequest::class, ['status' => 'archived', 'priority' => 7]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'status'   => [['rule' => 'in', 'params' => ['draft', 'published']]],
                'priority' => [['rule' => 'in', 'params' => ['1', '2']]],
            ], $e->getErrors());
        }
    }

    // ===== error keys follow the input =====

    public function testErrorKeysAreTheInputKeysTheClientUsed(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(InputKeyedRequest::class, [
                'order' => ['lines' => [['product' => 'Widget']]],
            ], context: []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'display_name'           => [['rule' => 'required']],
                'product_id'             => [['rule' => 'required']],
                'authUserId'             => [['rule' => 'required']],
                'order.lines.0.quantity' => [['rule' => 'required']],
            ], $e->getErrors());
        }
    }

    public function testHandlerErrorKeyedByPropertyNameIsMovedToTheInputKey(): void
    {
        try {
            $this->handler->handleArray(RenamedRejectingRequest::class, ['first_name' => 'x']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['first_name' => [['rule' => 'rejected']]], $e->getErrors());
        }
    }

    public function testPropertiesSharingAnInputKeyMergeTheirErrors(): void
    {
        $this->validator->method('validate')->willReturn([
            'id'        => [['rule' => 'integer']],
            'productId' => [['rule' => 'exists']],
        ]);

        try {
            $this->handler->handleArray(SharedRouteKeyRequest::class, [], route: ['id' => 'x']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['id' => [['rule' => 'integer'], ['rule' => 'exists']]], $e->getErrors());
        }
    }

    public function testChildErrorSharingAFlatPropertysKeyIsMerged(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(CollidingPathRequest::class, ['address' => []]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // One from #[FromBody('address.zip')], one from the nested DTO.
            $this->assertSame(
                ['address.zip' => [['rule' => 'required'], ['rule' => 'required']]],
                $e->getErrors()
            );
        }
    }

    // ===== nested DTOs =====

    public function testNestedDtoIsBuiltFromAnObject(): void
    {
        $dto = $this->handler->handleArray(ShipmentRequest::class, [
            'carrier' => 'ups',
            'address' => ['city' => 'Kyiv', 'zip' => '01001'],
        ]);

        $this->assertInstanceOf(AddressRequest::class, $dto->address);
        $this->assertSame('Kyiv', $dto->address->city);
        $this->assertSame(
            ['carrier' => 'ups', 'address' => ['city' => 'Kyiv', 'zip' => '01001'], 'billing' => null],
            $dto->toArray()
        );
    }

    public function testAbsentNestedDtoStaysUninitialised(): void
    {
        $dto = $this->handler->handleArray(ShipmentRequest::class, ['carrier' => 'ups']);

        $this->assertFalse($dto->has('address'));
        $this->assertNull($dto->billing);
    }

    public function testNestedDtoErrorsAreKeyedByPath(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(ShipmentRequest::class, [
                'address' => ['city' => 'Kyiv'],
                'billing' => 'oops',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'carrier'     => [['rule' => 'required']],
                'address.zip' => [['rule' => 'required']],
                'billing'     => [['rule' => 'array']],
            ], $e->getErrors());
        }
    }

    public function testNullForNonNullableNestedDtoIsRequired(): void
    {
        try {
            $this->handler->handleArray(ShipmentRequest::class, ['carrier' => 'ups', 'address' => null]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['address' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    public function testNestedDtoFromPostProcessorOutput(): void
    {
        $dto = $this->handler->handleArray(JsonAddressShipmentRequest::class, [
            'address' => '{"city":"Lviv","zip":"79000"}',
        ]);

        $this->assertSame('Lviv', $dto->address->city);
    }

    public function testItemsInsideNestedDtoReportTheFullPath(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(TransferRequest::class, [
                'from' => ['name' => 'Main', 'stock' => [['product' => 'Widget']]],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['from.stock.0.quantity' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    public function testNestedGeneratorsWaitForTheWholeTree(): void
    {
        CountingGenerator::$calls = 0;
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(ParentWithGeneratedAddressRequest::class, ['address' => ['city' => 'Kyiv']]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['title'], array_keys($e->getErrors()));
        }

        $this->assertSame(0, CountingGenerator::$calls);
    }

    // ===== handler-thrown validation errors =====

    public function testHandlerValidationErrorsInsideItemsKeepTheItemPath(): void
    {
        // Review #1: a caster rejecting an item's value must report it under
        // `lines.N.`, aggregated across items, and before any generator runs.
        CountingGenerator::$calls = 0;

        try {
            $this->handler->handleArray(MoneyOrderRequest::class, [
                'lines' => [['amount' => '1.00'], ['amount' => 'abc'], ['amount' => 'x']],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'lines.1.amount' => [['rule' => 'money']],
                'lines.2.amount' => [['rule' => 'money']],
            ], $e->getErrors());
        }

        $this->assertSame(0, CountingGenerator::$calls);
    }

    public function testPreProcessorValidationErrorsAreAggregatedWithValidatorErrors(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(SkuOrderRequest::class, [
                'lines' => [['sku' => 'abc', 'qty' => '1'], ['sku' => 'OK']],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'title'       => [['rule' => 'required']],
                'lines.0.sku' => [['rule' => 'sku']],
                'lines.1.qty' => [['rule' => 'required']],
            ], $e->getErrors());
        }
    }

    public function testPostProcessorValidationErrorsOfAllFieldsAreReported(): void
    {
        try {
            $this->handler->handleArray(TwoRejectingFieldsRequest::class, ['first' => 'a', 'second' => 'b']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'first'  => [['rule' => 'rejected']],
                'second' => [['rule' => 'rejected']],
            ], $e->getErrors());
        }
    }

    // ===== post-processed items =====

    public function testItemsPostProcessorWaitsForTheRestOfTheInput(): void
    {
        // Review #3: a post-processor never runs while other fields are invalid.
        CountingJsonItemsProcessor::$calls = 0;
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(TitledJsonItemsRequest::class, [
                'items' => '[{"product":"Widget","quantity":"2"}]',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['title'], array_keys($e->getErrors()));
        }

        $this->assertSame(0, CountingJsonItemsProcessor::$calls);
    }

    public function testItemsProducedByPostProcessorAreValidated(): void
    {
        $this->requireWhenMissing();

        try {
            $this->handler->handleArray(TitledJsonItemsRequest::class, [
                'title' => 'Order',
                'items' => '[{"product":"Widget"}]',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['items.0.quantity' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    // ===== array guard =====

    public function testFromBodyArrayIsHandedToPostProcessor(): void
    {
        // Review #4: a processor may turn the array a dot-path points at into
        // the declared type; the array guard only applies to built-in casts.
        $dto = $this->handler->handleArray(FromBodyTagsRequest::class, ['meta' => ['tags' => ['a', 'b']]]);

        $this->assertSame('a,b', $dto->tags);
    }

    public function testFromBodyArrayLeafReachesTheValidator(): void
    {
        $this->validator->expects($this->once())
            ->method('validate')
            ->with(['customerId' => ['nested' => 1]], ['customerId' => 'required|integer'])
            ->willReturn(['customerId' => [['rule' => 'integer']]]);

        try {
            $this->handler->handleArray(FromBodyOrderRequest::class, ['customer' => ['id' => ['nested' => 1]]]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['customer.id' => [['rule' => 'integer']]], $e->getErrors());
        }
    }

    public function testArrayForStringPropertyIsRejected(): void
    {
        // 4.0: a string property no longer JSON-encodes an array silently.
        try {
            $this->handler->handleArray(SortRequest::class, ['sort' => ['x']]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['sort' => [['rule' => 'string']]], $e->getErrors());
        }
    }

    public function testJsonCastEncodesStructuredInputIntoAString(): void
    {
        $dto = $this->handler->handleArray(JsonSettingsRequest::class, ['settings' => ['theme' => 'dark']]);

        $this->assertSame('{"theme":"dark"}', $dto->settings);
    }

    public function testValuesThatCannotBeConvertedLosslesslyAreErrors(): void
    {
        try {
            $this->handler->handleArray(StrictScalarsRequest::class, [
                'qty'    => '12abc',
                'price'  => '9,99',
                'active' => 'maybe',
                'label'  => true,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'qty'    => [['rule' => 'integer']],
                'price'  => [['rule' => 'numeric']],
                'active' => [['rule' => 'boolean']],
                'label'  => [['rule' => 'string']],
            ], $e->getErrors());
        }
    }

    public function testOverflowToInfinityIsNotANumber(): void
    {
        try {
            $this->handler->handleArray(StrictScalarsRequest::class, ['price' => '1e400']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['price' => [['rule' => 'numeric']]], $e->getErrors());
        }
    }

    public function testBooleanIsNotANumber(): void
    {
        // 3.x and early 4.0 stored true as 1 / 1.0 — a JSON boolean sent for a number.
        try {
            $this->handler->handleArray(StrictScalarsRequest::class, ['qty' => true, 'price' => false]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'qty'   => [['rule' => 'integer']],
                'price' => [['rule' => 'numeric']],
            ], $e->getErrors());
        }
    }

    public function testEmptyStringForOptionalBoolMeansNoValue(): void
    {
        $dto = $this->handler->handleArray(StrictScalarsRequest::class, ['enabled' => '']);

        $this->assertNull($dto->enabled);
    }

    // ===== explicit empty values =====

    public function testEmptyValueForPropertyThatNeedsOneIsRequiredError(): void
    {
        // Review #6: "" or null for a non-nullable property without a default
        // used to leave it silently uninitialised.
        try {
            $this->handler->handleArray(RequiredByTypeRequest::class, [
                'status'   => '',
                'qty'      => null,
                'optional' => null,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'status' => [['rule' => 'required']],
                'qty'    => [['rule' => 'required']],
            ], $e->getErrors());
        }
    }
}

// ============================================================================
// Fixtures
// ============================================================================

final class BasicRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    #[Validate('required|numeric')]
    public float $price;

    public ?string $description = null;

    public int $page = 1;
}

final class UpdateProductRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[Validate('required|string')]
    public string $name;
}

final class UpdateWithExplicitKeyRequest extends Request
{
    #[FromRoute('product_id')]
    #[Validate('required|integer')]
    public int $productId;

    #[Validate('required|string')]
    public string $name;
}

final class ContextRequest extends Request
{
    #[FromContext('authUserId')]
    public int $createdBy;

    #[Validate('required|string')]
    public string $name;
}

final class OrderItemWithIdRequest extends Request
{
    #[Validate('required|integer')]
    public int $id;

    #[Validate('required|string')]
    public string $product;

    #[Validate('required|integer')]
    public int $quantity;
}

final class OrderWithIdItemsRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var OrderItemWithIdRequest[]|null */
    #[Validate('nullable|array')]
    #[Items(OrderItemWithIdRequest::class)]
    public ?array $items = null;
}

final class ProcessingRequest extends Request
{
    #[PreProcess('trim')]
    public string $name;

    #[PostProcess('slugify')]
    public string $slug;

    public static function slugify(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
}

final class CustomCasterRequest extends Request
{
    #[Caster(CustomCaster::class)]
    public int $id;
}

final class PreProcessorRequest extends Request
{
    #[PreProcess(TestPreProcessor::class)]
    public string $value;
}

final class PostProcessorRequest extends Request
{
    #[PostProcess(TestPostProcessor::class)]
    public string $value;
}

final class CasterAsProcessorRequest extends Request
{
    #[PreProcess(TestCasterAsProcessor::class)]
    public string $value;
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
    public function process(mixed $value, ProcessContext $context): string
    {
        return 'pre_' . $value;
    }
}

final class TestPostProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
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

final class GroupedRequest extends Request
{
    #[Group('criteria')]
    public ?string $search = null;

    #[Validate('in:only,with')]
    #[Group('criteria')]
    public ?string $deleted = null;

    #[Validate('min:1')]
    public int $page = 1;

    #[Validate('min:1|max:100')]
    public int $per_page = 20;
}

final class MapToGroupedRequest extends Request
{
    #[Validate('integer')]
    #[Group('criteria', mapTo: 'positions.id')]
    public int $position_id;

    #[Group('criteria')]
    public ?string $search = null;

    #[Validate('min:1')]
    public int $page = 1;
}

final class NoAttributeRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    public ?string $email = null;
    public int $age = 18;
}

final class ProtectedInternalRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    protected string $internal = 'default-internal';

    public function reveal(): string
    {
        return $this->internal;
    }
}

final class IgnorePublicRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    #[Ignore]
    public string $computed = 'controller-default';
}

final class IgnoreWithValidateConflictRequest extends Request
{
    #[Ignore]
    #[Validate('required|string')]
    public string $foo;
}

final class FromBodyOrderRequest extends Request
{
    #[FromBody('customer.id')]
    #[Validate('required|integer')]
    public int $customerId;

    #[Validate('nullable|string')]
    public ?string $note = null;
}

final class FromBodyDeepRequest extends Request
{
    #[FromBody('shipping.address.zip')]
    public string $shippingZip;
}

final class FromBodyWithDefaultRequest extends Request
{
    #[FromBody('customer.id')]
    public int $customerId = 0;
}

final class FromBodyFlatRequest extends Request
{
    #[FromBody('display_name')]
    public string $name;
}

final class FromBodyAndRouteConflictRequest extends Request
{
    #[FromBody('foo')]
    #[FromRoute]
    public string $foo;
}

final class FromBodyScalarMismatchRequest extends Request
{
    #[FromBody('shipping')]
    public string $shipping;
}

final class OptionalEmailRequest extends Request
{
    #[Validate('nullable|email')]
    public ?string $email = null;
}

final class GeneratorRequiredDefaultRequest extends Request
{
    #[Generator(\Solo\RequestHandler\Tests\Integration\ConflictTestGenerator::class)]
    #[Validate('required|string')]
    public string $id = 'default';
}

final class ConflictTestGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return 'generated';
    }
}

final class ConditionalCompanyRequest extends Request
{
    #[Validate('in:individual,company')]
    public string $company_type = 'individual';

    #[Validate('nullable|string|max:255|required_if:company_type,company')]
    public ?string $name = null;

    #[Validate('integer|min:1')]
    public int $page = 1;
}

final class DefaultsOnlyRequest extends Request
{
    #[Validate('in:individual,company')]
    public string $company_type = 'individual';

    #[Validate('integer|min:1')]
    public int $page = 1;
}

final class OptionalNoRulesRequest extends Request
{
    public ?string $value = null;
}

final class AutoTrimArrayRequest extends Request
{
    public string $name;
    /** @var array<string> */
    public array $tags = [];
}

final class NonNullableRequest extends Request
{
    public int $id;
    public string $name;
}

final class UntypedPassThroughRequest extends Request
{
    /** @var mixed */
    public $anything;
}

final class ExcludeRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    #[Validate('required|string|min:8')]
    #[Exclude]
    public string $password;
}

final class ContextProcessorRequest extends Request
{
    #[Validate('required|string')]
    #[PostProcess(RouteAndContextAwareProcessor::class)]
    public string $slug;
}

final class RouteAndContextAwareProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $id = $context->route['id'] ?? '';
        $tenant = $context->context['tenant'] ?? '';
        return "{$value}-{$id}-{$tenant}";
    }
}

final class PostProcessorSkipsCastRequest extends Request
{
    /** @var array<string>|null */
    #[PostProcess(JsonToArrayProcessor::class)]
    public ?array $tags = null;
}

final class JsonToArrayProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    /** @return array<string> */
    public function process(mixed $value, ProcessContext $context): array
    {
        return json_decode($value, true);
    }
}

final class TestConfigProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $prefix = $context->config['prefix'] ?? '';
        return $prefix . $value;
    }
}

final class PostProcessConfigRequest extends Request
{
    #[Validate('required|string')]
    #[PostProcess(TestConfigProcessor::class, config: ['prefix' => '$'])]
    public string $amount;
}

final class PostProcessNoConfigRequest extends Request
{
    #[Validate('required|string')]
    #[PostProcess(TestConfigProcessor::class)]
    public string $amount;
}

final class OrderItemRequest extends Request
{
    #[Validate('required|string')]
    public string $product;

    #[Validate('required|integer')]
    public int $quantity;
}

final class OrderRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var OrderItemRequest[]|null */
    #[Validate('nullable|array')]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;
}

final class NoRulesItemsRequest extends Request
{
    /** @var OrderItemRequest[]|null */
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;
}

final class GeneratorRequest extends Request
{
    #[Generator(TestUuidGenerator::class)]
    public string $id;

    #[Validate('required|string')]
    public string $name;
}

final class GeneratorWithOptionsRequest extends Request
{
    #[Generator(TestIdGenerator::class, options: ['table' => 'users', 'id' => 123])]
    public string $id;

    #[Validate('required|string')]
    public string $name;
}

final class RegisteredGeneratorRequest extends Request
{
    #[Generator(TestGeneratorWithDependency::class)]
    public string $id;

    #[Validate('required|string')]
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
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
             . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

final class TestIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return ($options['table'] ?? 'unknown') . '_' . ($options['id'] ?? 0);
    }
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

final class UnknownProcessorRequest extends Request
{
    #[PreProcess('nonExistentHandler')]
    public string $value;
}

final class NonStaticProcessorRequest extends Request
{
    #[PreProcess('normalize')]
    public string $value;

    public function normalize(string $v): string  // intentionally non-static
    {
        return $v;
    }
}

final class InRuleWithNullableLiteralRequest extends Request
{
    #[Validate('in:nullable,active')]
    public string $status;
}

// ===== configuration-error fixtures =====

final class NotACaster
{
    public function cast(mixed $value): string
    {
        return 'nope';
    }
}

final class InvalidCasterRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not implement CasterInterface */
    #[Caster(NotACaster::class)]
    public string $value;
}

final class CastAndCasterConflictRequest extends Request
{
    #[Cast('int')]
    #[Caster(CustomCaster::class)]
    public int $value;
}

final class CastWithClassRequest extends Request
{
    #[Cast(CustomCaster::class)]
    public int $value;
}

final class UnknownCastRequest extends Request
{
    #[Cast('unknown_type')]
    public string $value;
}

final class GeneratorWithValidateRequest extends Request
{
    #[Generator(TestUuidGenerator::class)]
    #[Validate('required|string')]
    public string $id;
}

final class GeneratorWithFromRouteRequest extends Request
{
    #[Generator(TestUuidGenerator::class)]
    #[FromRoute]
    public string $id;
}

final class GeneratorWithItemsRequest extends Request
{
    /** @var array<mixed>|null */
    #[Generator(TestUuidGenerator::class)]
    #[Items(OrderItemRequest::class)]
    public ?array $id = null;
}

final class ItemsWithCastRequest extends Request
{
    /** @var array<mixed> */
    #[Items(OrderItemRequest::class)]
    #[Cast('array')]
    public array $items = [];
}

final class MultipleSourcesRequest extends Request
{
    #[FromRoute]
    #[FromContext('value')]
    public string $value;
}

final class TenantScopedRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[FromContext('tenantId')]
    public int $tenantId;

    #[Validate('required|email')]
    public string $email;
}

final class OrderWithTwoItemListsRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var OrderItemRequest[]|null */
    #[Validate('nullable|array')]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;

    /** @var OrderItemRequest[]|null */
    #[Validate('nullable|array')]
    #[Items(OrderItemRequest::class)]
    public ?array $extras = null;
}

final class FromBodyCustomerRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    #[FromBody('customer.id')]
    public int $customerId;
}

final class PaginationRequest extends Request
{
    public int $page = 1;
    public bool $active = false;
}

final class CountingGenerator implements GeneratorInterface
{
    public static int $calls = 0;

    public function generate(array $options = []): int
    {
        return ++self::$calls;
    }
}

final class CountedGeneratorRequest extends Request
{
    #[Generator(CountingGenerator::class)]
    public int $id;

    #[Validate('required|string')]
    public string $name;
}

final class GeneratedLineRequest extends Request
{
    #[Generator(CountingGenerator::class)]
    public int $id;

    #[Validate('required|string')]
    public string $sku;
}

final class GeneratedLinesRequest extends Request
{
    #[Validate('required|string')]
    public string $title;

    /** @var GeneratedLineRequest[] */
    #[Validate('required|array')]
    #[Items(GeneratedLineRequest::class)]
    public array $lines;
}

final class LowercaseEmailRequest extends Request
{
    #[PreProcess('strtolower')]
    #[Validate('required|email')]
    public string $email;
}

final class UppercaseCodeRequest extends Request
{
    #[Validate('nullable|string')]
    #[PostProcess('strtoupper')]
    public ?string $code = null;
}

abstract class BaseSlugRequest extends Request
{
    #[PostProcess('normalizeSlug')]
    public string $slug;
}

final class ConcreteSlugRequest extends BaseSlugRequest
{
    public static function normalizeSlug(string $value): string
    {
        return str_replace(' ', '-', strtolower($value));
    }
}

final class JsonItemsRequest extends Request
{
    /** @var OrderItemRequest[]|null */
    #[PostProcess(JsonToArrayProcessor::class)]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;
}

final class DateTimeFormatsRequest extends Request
{
    #[DateFormat('Y-m-d H:i:s')]
    public \DateTime $eventTime;

    #[DateFormat('Y-m-d')]
    public ?\DateTimeImmutable $day = null;
}

final class TypeDrivenDateTimeRequest extends Request
{
    public \DateTimeImmutable $immutable;
    public \DateTime $mutable;
    public \DateTimeInterface $either;
}

final class ContextClockRequest extends Request
{
    #[FromContext('now')]
    public \DateTimeImmutable $now;
}

enum TestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum TestPriority: int
{
    case Low = 1;
    case High = 2;
}

final class EnumRequest extends Request
{
    public TestStatus $status;
    public TestPriority $priority = TestPriority::Low;
    public ?TestStatus $optional = null;
}

final class RejectingMoneyCaster implements \Solo\RequestHandler\Contracts\CasterInterface
{
    public function cast(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new ValidationException(['amount' => [['rule' => 'money']]]);
        }
        return (int) round((float) $value * 100);
    }
}

final class MoneyLineRequest extends Request
{
    #[Generator(CountingGenerator::class)]
    public int $id;

    #[Caster(RejectingMoneyCaster::class)]
    public int $amount;
}

final class MoneyOrderRequest extends Request
{
    /** @var MoneyLineRequest[] */
    #[Items(MoneyLineRequest::class)]
    public array $lines = [];
}

final class StrictSkuProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value) || preg_match('/^[A-Z]+$/', $value) !== 1) {
            throw new ValidationException(['sku' => [['rule' => 'sku']]]);
        }
        return $value;
    }
}

final class SkuLineRequest extends Request
{
    #[PreProcess(StrictSkuProcessor::class)]
    public string $sku;

    #[Validate('required|integer')]
    public int $qty;
}

final class SkuOrderRequest extends Request
{
    #[Validate('required|string')]
    public string $title;

    /** @var SkuLineRequest[] */
    #[Items(SkuLineRequest::class)]
    public array $lines = [];
}

final class RejectingProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): mixed
    {
        $field = $context->config['field'] ?? null;
        throw new ValidationException([is_string($field) ? $field : 'value' => [['rule' => 'rejected']]]);
    }
}

final class TwoRejectingFieldsRequest extends Request
{
    #[PostProcess(RejectingProcessor::class, config: ['field' => 'first'])]
    public string $first;

    #[PostProcess(RejectingProcessor::class, config: ['field' => 'second'])]
    public string $second;
}

final class CountingJsonItemsProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public static int $calls = 0;

    public function process(mixed $value, ProcessContext $context): mixed
    {
        self::$calls++;
        return is_string($value) ? json_decode($value, true) : $value;
    }
}

final class TitledJsonItemsRequest extends Request
{
    #[Validate('required|string')]
    public string $title;

    /** @var OrderItemRequest[]|null */
    #[PostProcess(CountingJsonItemsProcessor::class)]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;
}

final class ImplodeProcessor implements \Solo\RequestHandler\Contracts\ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): mixed
    {
        return is_array($value) ? implode(',', $value) : $value;
    }
}

final class FromBodyTagsRequest extends Request
{
    #[FromBody('meta.tags')]
    #[PostProcess(ImplodeProcessor::class)]
    public string $tags;
}

final class SortRequest extends Request
{
    public string $sort = 'id';
}

final class ArrayGenerator implements GeneratorInterface
{
    /** @return list<string> */
    public function generate(array $options = []): array
    {
        return ['oops'];
    }
}

final class BrokenGeneratorRequest extends Request
{
    #[Generator(ArrayGenerator::class)]
    public int $id;
}

final class RequiredByTypeRequest extends Request
{
    public TestStatus $status;
    public int $qty;
    public ?int $optional;
}

final class AddressRequest extends Request
{
    #[Validate('required|string')]
    public string $city;

    #[Validate('required|string')]
    public string $zip;
}

final class ShipmentRequest extends Request
{
    #[Validate('required|string')]
    public string $carrier;

    public AddressRequest $address;

    public ?AddressRequest $billing = null;
}

final class JsonAddressShipmentRequest extends Request
{
    #[PostProcess(JsonToArrayProcessor::class)]
    public AddressRequest $address;
}

final class WarehouseRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var OrderItemRequest[] */
    #[Items(OrderItemRequest::class)]
    public array $stock = [];
}

final class TransferRequest extends Request
{
    public WarehouseRequest $from;
}

final class GeneratedAddressRequest extends Request
{
    #[Generator(CountingGenerator::class)]
    public int $id;

    #[Validate('required|string')]
    public string $city;
}

final class ParentWithGeneratedAddressRequest extends Request
{
    #[Validate('required|string')]
    public string $title;

    public GeneratedAddressRequest $address;
}

final class InputKeyedRequest extends Request
{
    #[FromBody('display_name')]
    #[Validate('required|string')]
    public string $name;

    #[FromRoute('product_id')]
    #[Validate('required|integer')]
    public int $productId;

    #[FromContext('authUserId')]
    #[Validate('required|integer')]
    public int $createdBy;

    /** @var OrderItemRequest[] */
    #[FromBody('order.lines')]
    #[Items(OrderItemRequest::class)]
    public array $lines = [];
}

final class RenamedRejectingRequest extends Request
{
    #[FromBody('first_name')]
    #[PostProcess(RejectingProcessor::class, config: ['field' => 'firstName'])]
    public string $firstName;
}

final class SharedRouteKeyRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[FromRoute('id')]
    #[Validate('required|exists:products,id')]
    public int $productId;
}

final class JsonSettingsRequest extends Request
{
    #[Cast('json')]
    public ?string $settings = null;
}

final class StrictScalarsRequest extends Request
{
    public ?int $qty = null;
    public ?float $price = null;
    public ?bool $active = null;
    public ?string $label = null;
    public ?bool $enabled = null;
}

final class BlankFieldsRequest extends Request
{
    #[EmptyToNull]
    #[Validate('nullable|date')]
    public ?string $endDate = null;

    #[Validate('nullable|string')]
    public ?string $title = null;

    #[EmptyToNull]
    public string $sort = 'id';

    #[EmptyToNull]
    public string $code;

    #[EmptyToNull]
    #[PreProcess('strtolower')]
    public ?string $slug = null;
}

#[EmptyToNull]
final class BlankClassRequest extends Request
{
    public ?string $note = null;

    /** @var list<string>|null */
    public ?array $tags = null;
}

final class PatchProductRequest extends Request
{
    #[FromRoute]
    public int $id;

    public ?string $name = null;
    public ?string $note = null;
    public int $stock = 0;
    public ?float $price = null;

    #[FromContext('userId')]
    public int $updatedBy;

    #[Generator(ConflictTestGenerator::class)]
    public string $stamp;
}

final class PatchAddressRequest extends Request
{
    public ?string $city = null;
    public ?string $zip = null;
}

final class PatchLineRequest extends Request
{
    public int $qty = 1;
    public ?string $sku = null;
}

final class PatchOrderRequest extends Request
{
    public ?PatchAddressRequest $address = null;

    /** @var list<PatchLineRequest> */
    #[Items(PatchLineRequest::class)]
    public array $lines = [];

    public ?string $comment = null;
}

final class ProvidedNameClashRequest extends Request
{
    public ?string $provided = null;
}

final class ArrayContainer implements ContainerInterface
{
    public int $gets = 0;

    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class ("No entry {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        $this->gets++;
        $entry = $this->entries[$id];

        // A closure is a factory: a new instance per get().
        return $entry instanceof \Closure ? $entry() : $entry;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class NoTrimRequest extends Request
{
    #[NoTrim]
    #[Validate('required|string')]
    public string $snippet;

    public ?string $title = null;
}

#[NoTrim]
final class NoTrimClassRequest extends Request
{
    public ?string $a = null;
    public ?string $b = null;

    #[EmptyToNull]
    public ?string $c = null;
}

final class UnionTypesRequest extends Request
{
    public int|float $amount = 0;
    public int|float $ratio = 0;
    public int|string $code = 0;
    public int|bool $flag = false;

    /** @var array<mixed>|string */
    public array|string $tags = '';
}

final class ContextUnionRequest extends Request
{
    #[FromContext('limit')]
    public int|float $limit;
}

#[EmptyToNull]
final class BlankClientInputRequest extends Request
{
    public ?string $note = null;

    #[FromContext('tenant')]
    public string $tenant;
}

final class NullGenerator implements GeneratorInterface
{
    public function generate(array $options = []): mixed
    {
        return null;
    }
}

final class NullGeneratedRequest extends Request
{
    #[Generator(NullGenerator::class)]
    public string $token;
}

final class ContextAddressRequest extends Request
{
    #[FromContext('address')]
    public AddressRequest $address;
}

final class TrustedScalarsRequest extends Request
{
    #[FromContext('count')]
    public ?int $count = null;

    #[FromContext('ratio')]
    public ?float $ratio = null;

    #[FromContext('flag')]
    public ?bool $flag = null;

    #[FromContext('label')]
    public ?string $label = null;
}

final class NullingPostProcessRequest extends Request
{
    #[PostProcess('returnsNull')]
    public string $slug;

    #[PostProcess('returnsNull')]
    public int $page = 1;

    public static function returnsNull(mixed $value): mixed
    {
        return null;
    }
}

final class CollidingPathRequest extends Request
{
    #[FromBody('address.zip')]
    #[Validate('required|string')]
    public string $zip;

    public ?CollidingAddressRequest $address = null;
}

final class CollidingAddressRequest extends Request
{
    #[Validate('required|string')]
    public string $zip;
}

#[NoTrim]
final class UntrimmedClassRequest extends Request
{
    public ?string $note = null;

    #[FromContext('token')]
    public string $token;
}

final class CompactDateRequest extends Request
{
    #[DateFormat('Ymd')]
    public ?\DateTimeImmutable $day = null;
}

final class WrongObjectGenerator implements GeneratorInterface
{
    public function generate(array $options = []): mixed
    {
        return new \stdClass();
    }
}

final class WrongObjectGeneratedRequest extends Request
{
    #[Generator(WrongObjectGenerator::class)]
    public AddressRequest $address;
}

final class LineItemRequest extends Request
{
    public ?string $sku = null;
    public int $qty = 1;
}

final class ContextLinesRequest extends Request
{
    /** @var list<LineItemRequest> */
    #[FromContext('lines')]
    #[Items(LineItemRequest::class)]
    public array $lines = [];
}

final class TermsRequest extends Request
{
    public true $accepted;
    public string|false $nickname = false;
}

final class IterableRequest extends Request
{
    /** @var iterable<mixed> */
    public iterable $tags = [];

    /** @var iterable<mixed>|null */
    #[FromContext('feed')]
    public ?iterable $feed = null;

    /** @var array<mixed>|\ArrayObject<int|string, mixed> */
    public array|\ArrayObject $data = [];
}
