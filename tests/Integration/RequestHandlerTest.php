<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Attributes\EmptyToNull;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\NoTrim;
use Solo\RequestHandler\Attributes\PostProcess;
use Solo\RequestHandler\Attributes\Normalize;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\NormalizerInterface;
use Solo\RequestHandler\Contracts\PostProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\ProcessContext;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\RequestHandler;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solo\RequestHandler\Tests\ExpectsExceptionMessageParts;

#[AllowMockObjectsWithoutExpectations]
final class RequestHandlerTest extends TestCase
{
    use ExpectsExceptionMessageParts;

    private RequestHandler $handler;
    private ValidatorInterface&MockObject $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->handler = new RequestHandler($this->validator);
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

    // ===== auto-trim =====

    public function testNoTrimWithEmptyToNullStillReadsBlankAsNull(): void
    {
        $dto = $this->handler->handleArray(NoTrimClassRequest::class, ['a' => '   ', 'c' => '   ']);
        $this->assertSame('   ', $dto->a, 'no #[EmptyToNull]: kept as sent');
        $this->assertNull($dto->c, 'blank is still no value');

        $dto = $this->handler->handleArray(NoTrimClassRequest::class, ['c' => ' z ']);
        $this->assertSame(' z ', $dto->c, 'a filled value is not trimmed');
    }

    // ===== union types =====

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

    public function testEmptyStringIsReadByTheTypesOwnReader(): void
    {
        // An enum with a "" case reads "" as that case, not as no value.
        $filter = fn(string $input) => $this->handler->handleArray(BlankFilterRequest::class, ['filter' => $input]);
        $this->assertSame(BlankFilter::All, $filter('')->filter);
        $this->assertSame(BlankFilter::Active, $filter('active')->filter);
    }

    // ===== application-supplied values =====

    public function testGeneratorReturningNullForARequiredPropertyThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Value generated by');
        $this->handler->handleArray(NullGeneratedRequest::class, []);
    }

    public function testPostProcessedContextListMembersAreChecked(): void
    {
        // The context list is checked as it is read, before its
        // post-processor runs.
        $line = new LineItemRequest();
        $line->sku = 'A-1';
        $dto = $this->handler->handleArray(PostProcessedContextLinesRequest::class, [], context: ['lines' => [$line]]);
        $this->assertSame([$line], $dto->lines);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'lines' holds array at key 0, but");
        $this->handler->handleArray(PostProcessedContextLinesRequest::class, [], context: [
            'lines' => [['sku' => 'A-1']],
        ]);
    }

    public function testInvalidContextBlocksFinalizationButNotPureNormalization(): void
    {
        // Pure normalizers run in resolve; generators wait for valid context.
        CountingGenerator::$calls = 0;
        CountingJsonNormalizer::$calls = 0;
        $body = ['tags' => '["a"]'];

        foreach ([['tenant' => null], ['tenant' => 'acme', 'address' => ['city' => 'Kyiv']]] as $index => $context) {
            try {
                $this->handler->handleArray(ContextBeforeHandlersRequest::class, $body, context: $context);
                $this->fail('Expected UnexpectedValueException');
            } catch (\UnexpectedValueException) {
                $this->assertSame($index + 1, CountingJsonNormalizer::$calls, (string) json_encode($context));
                $this->assertSame(0, CountingGenerator::$calls, (string) json_encode($context));
            }
        }

        $dto = $this->handler->handleArray(ContextBeforeHandlersRequest::class, $body, context: ['tenant' => 'acme']);
        $this->assertSame(['a'], $dto->tags);
        $this->assertSame(1, $dto->sequence);
    }

    public function testContextValueThatFitsNoUnionMemberThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'limit' does not fit");
        $this->handler->handleArray(ContextUnionRequest::class, [], context: ['limit' => 'many']);
    }

    // ===== #[EmptyToNull] =====

    public function testBlankStringFallsBackToTheDefaultOrIsRequired(): void
    {
        $dto = $this->handler->handleArray(BlankFieldsRequest::class, ['sort' => '']);
        $this->assertSame('id', $dto->sort);

        try {
            $this->handler->handleArray(BlankFieldsRequest::class, ['code' => '']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['code' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    // ===== sources =====

    public function testRouteAndContextPropertiesNeverReadTheBody(): void
    {
        $body = ['id' => '999', 'authUserId' => 999, 'createdBy' => 999];

        $dto = $this->handler->handleArray(SourcedRequest::class, $body);
        $this->assertNull($dto->id);
        $this->assertNull($dto->createdBy);
        $this->assertSame([], $dto->provided());

        $dto = $this->handler->handleArray(
            SourcedRequest::class,
            $body,
            route: ['id' => '5'],
            context: ['authUserId' => 7],
        );
        $this->assertSame(5, $dto->id);
        $this->assertSame(7, $dto->createdBy);
    }

    // ===== validation payload =====

    public function testAbsentFieldReachesTheValidatorAsRulesWithoutAValue(): void
    {
        // Its default in the payload would make the field look sent as null,
        // so `email` would fail for a field nobody sent and nothing requires.
        // `note` has no presence rule and goes all the same: whether a field
        // may be missing is the validator's to decide.
        $this->validator->expects($this->once())->method('validate')
            ->with(['type' => 'person'], ['email' => 'required_if:type,company|email', 'note' => 'string|max:10'])
            ->willReturn([]);

        $dto = $this->handler->handleArray(ConditionalEmailRequest::class, ['type' => 'person']);

        $this->assertNull($dto->email);
        $this->assertSame(['type'], $dto->provided());
    }

    // ===== provided() =====

    public function testEachListItemReportsWhatItsOwnInputSupplied(): void
    {
        $dto = $this->handler->handleArray(PatchLinesRequest::class, [
            'lines' => [['sku' => 'A'], ['qty' => '2'], ['sku' => 'C', 'qty' => '3'], ['sku' => null]],
        ]);

        // Items with the same fields share one set of names; an explicit null
        // is supplied, an absent key is not.
        $this->assertSame(
            [['sku'], ['qty'], ['sku', 'qty'], ['sku']],
            array_map(static fn(LineItemRequest $line) => $line->provided(), $dto->lines),
        );
        $this->assertSame(['lines' => [
            ['sku' => 'A'],
            ['qty' => 2],
            ['sku' => 'C', 'qty' => 3],
            ['sku' => null],
        ]], $dto->toArray(onlyProvided: true));
    }

    public function testProvidedTracksInputRatherThanLaterDtoChanges(): void
    {
        $dto = $this->handler->handleArray(PatchAddressRequest::class, ['city' => 'Kyiv']);
        $names = $dto->provided();
        $names[] = 'zip';
        $dto->zip = '01001';
        unset($dto->city);

        $this->assertSame(['city'], $dto->provided());
        $this->assertTrue($dto->isProvided('city'));
        $this->assertFalse($dto->isProvided('zip'));
        $this->assertSame([], $dto->toArray(onlyProvided: true));
    }

    // ===== generator =====

    public function testContainerProvidesHandlersWithDependencies(): void
    {
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

    public function testContainerEntryOfTheWrongTypeIsAConfigurationError(): void
    {
        $handler = new RequestHandler(
            $this->validator,
            container: new ArrayContainer([TestGeneratorWithDependency::class => new \stdClass()]),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is a stdClass, not an instance of');
        $handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'Test']);
    }

    // ===== items =====

    public function testItemsExplicitNullDoesNotThrow(): void
    {
        // Explicit `{"items": null}` on a nullable items property must pass
        // through, not raise spurious ValidationException('array').
        $request = $this->makeRequest(body: ['name' => 'Order #1', 'items' => null]);

        $dto = $this->handler->handleBody(OrderRequest::class, $request);

        $this->assertNull($dto->items);
    }

    // ===== groups =====

    public function testGroupSkipsNullScalarMembers(): void
    {
        // Pre-2.0 group() emitted ['search' => null, 'deleted' => null]; now nulls are skipped.
        $request = $this->makeRequest(query: ['page' => '1']);

        $dto = $this->handler->handleQuery(GroupedRequest::class, $request);

        $this->assertSame([], $dto->group('criteria'));
    }

    // ===== PSR-7 object body =====

    public function testObjectBodyIsNormalisedToArray(): void
    {
        // Middleware-decoded JSON (`json_decode($json)` without assoc=true)
        // returns stdClass — body normalisation must convert it to array.
        $body = (object)['name' => 'Alice', 'nested' => (object)['key' => 'value']];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn([]);

        $dto = $this->handler->handleBody(NoAttributeRequest::class, $request);

        $this->assertSame('Alice', $dto->name);
    }

    // ===== register() type guard =====

    public function testRegisterWithMismatchedInstanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a');
        $this->handler->register(\DateTimeImmutable::class, new \stdClass());
    }

    // ===== configuration exceptions =====

    /** @return iterable<string, non-empty-list<string>> */
    public static function misconfigurations(): iterable
    {
        yield '#[Ignore] with a companion' => [IgnoreWithValidateConflictRequest::class, '#[Ignore]'];
        yield 'two sources' => [FromBodyAndRouteConflictRequest::class, 'multiple source attributes'];
        yield 'class name in #[Cast]' => [CastWithClassRequest::class, "#[Cast('Solo"];
        yield 'unknown cast type' => [UnknownCastRequest::class, 'which is neither a built-in type'];
        yield '#[Cast] with #[Caster]' => [CastAndCasterConflictRequest::class, 'has both #[Cast] and #[Caster]'];
        yield '#[Generator] with a source' => [GeneratorWithFromRouteRequest::class, 'FromRoute'];
        yield '#[Items] with #[Cast]' => [ItemsWithCastRequest::class, 'Items', 'Cast'];
    }

    /** @param class-string<Request> $class */
    #[DataProvider('misconfigurations')]
    public function testMisconfigurationFailsTheRequest(string $class, string ...$parts): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageParts(...$parts);
        $this->handler->handleArray($class, []);
    }

    // ===== processors =====

    public function testNestedPostProcessorsReceiveDecodedChildren(): void
    {
        $handle = fn(array $input) => $this->handler->handleArray(PostProcessedChildrenRequest::class, $input);

        $dto = $handle(['lines' => [['sku' => 'A', 'qty' => '2']], 'address' => ['city' => 'Kyiv']]);
        $this->assertSame([['sku' => 'A', 'qty' => 2]], array_map(static fn($line) => $line->toArray(), $dto->lines));
        $this->assertSame('Kyiv', $dto->address?->city);

        // "" is an empty list and no object.
        $dto = $handle(['lines' => '', 'address' => '']);
        $this->assertSame([], $dto->lines);
        $this->assertNull($dto->address);

        // Any other string is left for the children to reject.
        try {
            $handle(['lines' => 'abc']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['lines' => [['rule' => 'array']]], $e->getErrors());
        }
    }

    public function testUnregisteredHandlerWithDependenciesFailsClearly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Provide it through the container passed to RequestHandler, or register');
        $this->handler->handleArray(RegisteredGeneratorRequest::class, ['name' => 'x']);
    }

    // ===== error keys follow the input =====

    public function testCollidingItemPathsKeepEveryError(): void
    {
        try {
            $this->handler->handleArray(CollidingItemsRequest::class, [
                'items' => ['a' => ['code' => 'bad'], 'a.code' => 'bad'],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['items.a.code' => [['rule' => 'integer'], ['rule' => 'array']]], $e->getErrors());
        }
    }

    public function testThrownValidatorErrorsKeepPathsAndOrder(): void
    {
        $this->validator->expects($this->exactly(3))->method('validate')->willReturnCallback(
            static function (array $data, array $rules): never {
                // Deliberately report fields in a different order from the DTO.
                throw new ValidationException(isset($rules['name'])
                    ? [
                        'global.z' => [['rule' => 'request']],
                        'productId' => [['rule' => 'exists', 'params' => ['products', 'id']]],
                        'name' => [['rule' => 'required']],
                        'global.a' => [['rule' => 'request']],
                    ]
                    : [
                        'quantity' => [['rule' => 'min', 'params' => ['1']]],
                        'product' => [['rule' => 'required']],
                        'global' => [['rule' => 'item']],
                    ]);
            }
        );

        try {
            $this->handler->handleArray(InputKeyedRequest::class, [
                'display_name' => 'x',
                'order' => ['lines' => [3 => ['quantity' => 0], 1 => ['quantity' => 0]]],
            ], route: ['product_id' => '1'], context: ['authUserId' => 7]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $expected = [
                'display_name' => [['rule' => 'required']],
                'product_id' => [['rule' => 'exists', 'params' => ['products', 'id']]],
                'order.lines.3.product' => [['rule' => 'required']],
                'order.lines.3.quantity' => [['rule' => 'min', 'params' => ['1']]],
                'order.lines.3.global' => [['rule' => 'item']],
                'order.lines.1.product' => [['rule' => 'required']],
                'order.lines.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
                'order.lines.1.global' => [['rule' => 'item']],
                'global.z' => [['rule' => 'request']],
                'global.a' => [['rule' => 'request']],
            ];
            $this->assertSame($expected, $e->getErrors());
            $this->assertSame('Validation failed: ' . implode(', ', array_keys($expected)), $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
    }

    // ===== nested DTOs =====

    public function testSelfAndParentTypesNameTheirDeclaringClasses(): void
    {
        $origin = $this->handler->handleArray(NodeRequest::class, ['name' => 'origin']);
        $root = $this->handler->handleArray(NodeRequest::class, ['name' => 'root']);

        $dto = $this->handler->handleArray(LeafNodeRequest::class, [
            'name' => 'leaf',
            'next' => ['name' => 'next'],
            'base' => ['name' => 'base'],
        ], context: ['origin' => $origin, 'root' => $root]);

        // `self` is the declaring class, not the class being handled.
        $this->assertNotNull($dto->next);
        $this->assertNotNull($dto->base);
        $this->assertSame(NodeRequest::class, $dto->next::class);
        $this->assertSame('next', $dto->next->name);
        $this->assertSame(NodeRequest::class, $dto->base::class);
        $this->assertSame('base', $dto->base->name);
        $this->assertSame($origin, $dto->origin);
        $this->assertSame($root, $dto->root);
    }
}

// ============================================================================
// Fixtures
// ============================================================================

/** @implements CasterInterface<mixed> */
final class CustomCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): int
    {
        return (int) $value * 2;
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
}

final class NoAttributeRequest extends Request
{
    #[Validate('required|string')]
    public string $name;
}

final class IgnoreWithValidateConflictRequest extends Request
{
    #[Ignore]
    #[Validate('required|string')]
    public string $foo;
}

final class FromBodyAndRouteConflictRequest extends Request
{
    #[FromBody('foo')]
    #[FromRoute]
    public string $foo;
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

final class RegisteredGeneratorRequest extends Request
{
    #[Generator(TestGeneratorWithDependency::class)]
    public string $id;

    #[Validate('required|string')]
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

// ===== configuration-error fixtures =====

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

final class GeneratorWithFromRouteRequest extends Request
{
    #[Generator(NullGenerator::class)]
    #[FromRoute]
    public string $id;
}

final class ItemsWithCastRequest extends Request
{
    /** @var array<mixed> */
    #[Items(OrderItemRequest::class)]
    #[Cast('array')]
    public array $items = [];
}

final class CountingGenerator implements GeneratorInterface
{
    public static int $calls = 0;

    public function generate(array $options = []): int
    {
        return ++self::$calls;
    }
}

final class CountingJsonNormalizer implements NormalizerInterface
{
    public static int $calls = 0;

    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        self::$calls++;
        return is_string($value) ? json_decode($value, true) : $value;
    }
}

final class AddressRequest extends Request
{
    #[Validate('required|string')]
    public string $city;

    #[Validate('required|string')]
    public string $zip;
}

class NodeRequest extends Request
{
    public string $name = '';

    public ?self $next = null;

    #[FromContext('origin')]
    public ?self $origin = null;
}

final class LeafNodeRequest extends NodeRequest
{
    public ?parent $base = null;

    #[FromContext('root')]
    public ?parent $root = null;
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

final class BlankFieldsRequest extends Request
{
    #[EmptyToNull]
    public string $sort = 'id';

    #[EmptyToNull]
    public string $code;
}

final class PatchAddressRequest extends Request
{
    public ?string $city = null;
    public ?string $zip = null;
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

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

#[NoTrim]
final class NoTrimClassRequest extends Request
{
    public ?string $a = null;

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

final class CollidingItemsRequest extends Request
{
    /** @var list<CollidingItemRequest> */
    #[Items(CollidingItemRequest::class)]
    public array $items = [];
}

final class CollidingItemRequest extends Request
{
    public ?int $code = null;

    #[FromBody('nested.code')]
    public ?bool $nestedCode = null;
}

final class LineItemRequest extends Request
{
    public ?string $sku = null;
    public int $qty = 1;
}

final class PatchLinesRequest extends Request
{
    /** @var list<LineItemRequest> */
    #[Items(LineItemRequest::class)]
    public array $lines = [];
}

final class ConditionalEmailRequest extends Request
{
    public string $type = 'person';

    #[Validate('required_if:type,company|email')]
    public ?string $email = null;

    #[Validate('string|max:10')]
    public ?string $note = null;
}

final class SourcedRequest extends Request
{
    #[FromRoute]
    public ?int $id = null;

    #[FromContext('authUserId')]
    public ?int $createdBy = null;
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
}

final class ContextBeforeHandlersRequest extends Request
{
    #[Generator(CountingGenerator::class)]
    public int $sequence;

    /** @var list<string>|null */
    #[Normalize(CountingJsonNormalizer::class)]
    public ?array $tags = null;

    #[FromContext('tenant')]
    public string $tenant;

    #[FromContext('address')]
    public ?AddressRequest $address = null;
}

enum BlankFilter: string
{
    case All = '';
    case Active = 'active';
}

final class BlankFilterRequest extends Request
{
    public ?BlankFilter $filter = null;
}

final class PostProcessedContextLinesRequest extends Request
{
    /** @var list<LineItemRequest> */
    #[FromContext('lines')]
    #[Items(LineItemRequest::class)]
    #[PostProcess(PassThroughProcessor::class)]
    public array $lines = [];
}

final class PostProcessedChildrenRequest extends Request
{
    /** @var list<LineItemRequest> */
    #[PostProcess(PassThroughProcessor::class)]
    #[Items(LineItemRequest::class)]
    public array $lines = [];

    #[PostProcess(PassThroughProcessor::class)]
    public ?PatchAddressRequest $address = null;
}

/** @implements PostProcessorInterface<mixed> */
final class PassThroughProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        return $value;
    }
}
