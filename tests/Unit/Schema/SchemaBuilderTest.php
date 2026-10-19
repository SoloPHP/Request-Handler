<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Attributes\DateFormat;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\RequestHandler;
use Solo\RequestHandler\Schema\SchemaBuilder;

final class SchemaBuilderTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    /**
     * @param class-string $className
     * @return array<string, array<string, mixed>>
     */
    private function build(string $className): array
    {
        return (new SchemaBuilder($this->cache))->build($this->cache->get($className));
    }

    // ===== shape =====

    public function testFieldsAreKeyedByInputKeyAndLeaveOutNonInputs(): void
    {
        // Generated (`uuid`) and context (`contextId`) properties are not
        // part of what the client sends.
        $this->assertSame(
            [
                'product_id', 'name', 'nickname', 'company.type', 'companyName', 'status', 'page',
                'internal', 'lines', 'noRules', 'day', 'state', 'address', 'conditionalBad',
            ],
            array_keys($this->build(SchemaFixtureRequest::class)),
        );
    }

    public function testFieldNamesItsPropertyAndSource(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertSame('id', $schema['product_id']['property']);
        $this->assertSame('route', $schema['product_id']['source']);
        $this->assertSame('companyType', $schema['company.type']['property']);
        $this->assertSame('body', $schema['company.type']['source']);
        $this->assertSame('name', $schema['name']['property']);
        $this->assertNull($schema['name']['source']);
    }

    public function testRequiredNullableAndDefaultFlags(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertSame('string', $schema['name']['type']);
        $this->assertFalse($schema['name']['nullable']);
        $this->assertTrue($schema['name']['required']);
        $this->assertFalse($schema['name']['hasDefault']);
        $this->assertNull($schema['name']['default']);

        $this->assertTrue($schema['nickname']['nullable']);
        $this->assertFalse($schema['nickname']['required']);

        $this->assertSame('int', $schema['page']['type']);
        $this->assertTrue($schema['page']['hasDefault']);
        $this->assertSame(1, $schema['page']['default']);
    }

    public function testEnumFieldListsCasesAndExportsItsDefault(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['state'];

        $this->assertSame(['draft', 'live'], $field['cases']);
        $this->assertSame('draft', $field['default']);
    }

    public function testDateFieldCarriesItsFormat(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertSame('Y-m-d', $schema['day']['format']);
        $this->assertArrayNotHasKey('format', $schema['name']);
    }

    // ===== requiredIf =====

    public function testRequiredIfReferencesTheInputKey(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['companyName'];

        $this->assertSame(['field' => 'company.type', 'value' => 'company'], $field['requiredIf']);
        // required_if does NOT set the literal `required` flag.
        $this->assertFalse($field['required']);
    }

    public function testMalformedRequiredIfTreatedAsAbsent(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['conditionalBad'];

        $this->assertNull($field['requiredIf']);
        $this->assertContains(['name' => 'required_if', 'args' => ['onlyfield']], $field['rules']);
    }

    // ===== rules =====

    public function testRulesAreNormalized(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertContains(['name' => 'max', 'args' => ['255']], $schema['nickname']['rules']);
        $this->assertContains(['name' => 'in', 'args' => ['a', 'b']], $schema['status']['rules']);
        $this->assertSame([], $schema['noRules']['rules']);
    }

    // ===== nesting =====

    public function testNestedDtoAndItemsAreDescribedInline(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertSame(['city'], array_keys($schema['address']['fields']));
        $this->assertSame(['sku'], array_keys($schema['lines']['items']));
        $this->assertSame(
            [['name' => 'required', 'args' => []], ['name' => 'string', 'args' => []]],
            $schema['lines']['items']['sku']['rules']
        );
    }

    public function testPropertiesSharingAnInputKeyAreAllDescribed(): void
    {
        $schema = $this->build(SchemaSharedKeyRequest::class);

        $this->assertSame(['id'], array_keys($schema));
        $this->assertSame('id', $schema['id']['property']);
        $this->assertArrayHasKey('also', $schema['id']);
        $this->assertCount(1, $schema['id']['also']);
        $this->assertSame('productId', $schema['id']['also'][0]['property']);
        $this->assertContains(['name' => 'exists', 'args' => ['products', 'id']], $schema['id']['also'][0]['rules']);
        $this->assertArrayNotHasKey('also', $this->build(SchemaFixtureRequest::class)['name']);
    }

    public function testRecursiveClassIsReferenced(): void
    {
        $schema = $this->build(SchemaTreeRequest::class);

        $this->assertSame(['$ref' => SchemaTreeRequest::class], $schema['children']['items']);
    }

    // ===== JSON =====

    public function testSchemaIsLosslesslyJsonSerializable(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $json = json_encode($schema);
        $this->assertNotFalse($json);
        $this->assertSame($schema, json_decode($json, true));
    }

    public function testRequestHandlerSchemaWrapperMatchesBuilder(): void
    {
        $handler = new RequestHandler($this->createStub(ValidatorInterface::class));

        $this->assertSame($this->build(SchemaFixtureRequest::class), $handler->schema(SchemaFixtureRequest::class));
    }
}

// ============================================================================
// Fixtures
// ============================================================================

final class SchemaFixtureRequest extends Request
{
    #[FromRoute('product_id')]
    #[Validate('required|integer')]
    public int $id;

    #[Validate('required|string')]
    public string $name;

    #[Validate('nullable|string|max:255')]
    public ?string $nickname = null;

    #[FromBody('company.type')]
    #[Validate('nullable|in:person,company')]
    public ?string $companyType = null;

    #[Validate('nullable|string|required_if:companyType,company')]
    public ?string $companyName = null;

    #[Validate('nullable|in:a,b')]
    public ?string $status = null;

    #[Validate('integer|min_value:1')]
    public int $page = 1;

    #[Exclude]
    public string $internal = 'secret';

    /** @var array<int, array<string, mixed>>|null */
    #[Validate('nullable|array')]
    #[Items(SchemaLineRequest::class)]
    public ?array $lines = null;

    public string $noRules;

    #[FromContext('tenantId')]
    public int $contextId;

    #[Generator(SchemaIdGenerator::class)]
    public string $uuid;

    #[DateFormat('Y-m-d')]
    public ?\DateTimeImmutable $day = null;

    public SchemaState $state = SchemaState::Draft;

    public ?SchemaAddressRequest $address = null;

    #[Validate('nullable|required_if:onlyfield')]
    public ?string $conditionalBad = null;
}

final class SchemaLineRequest extends Request
{
    #[Validate('required|string')]
    public string $sku;
}

final class SchemaAddressRequest extends Request
{
    #[Validate('required|string')]
    public string $city;
}

final class SchemaTreeRequest extends Request
{
    public string $name;

    /** @var array<int, array<string, mixed>> */
    #[Items(SchemaTreeRequest::class)]
    public array $children = [];
}

enum SchemaState: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class SchemaIdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return 'id';
    }
}

final class SchemaSharedKeyRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[FromRoute('id')]
    #[Validate('required|exists:products,id')]
    public int $productId;
}
