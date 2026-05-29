<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Cache\PropertyMetadata;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Cache\RequestMetadata;
use Solo\RequestHandler\FieldSource;
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
     * @param  class-string $className
     * @return array<string, array{
     *     type: ?string,
     *     nullable: bool,
     *     required: bool,
     *     requiredIf: array{field: string, value: string}|null,
     *     hasDefault: bool,
     *     exclude: bool,
     *     source: ?string,
     *     rules: list<array{name: string, args: list<string>}>
     * }>
     */
    private function build(string $className): array
    {
        return (new SchemaBuilder())->build($this->cache->get($className));
    }

    // ===== scalar field flags =====

    public function testRequiredFieldFlags(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);
        $field = $schema['name'];

        $this->assertSame('string', $field['type']);
        $this->assertFalse($field['nullable']);
        $this->assertTrue($field['required']);
        $this->assertNull($field['requiredIf']);
        $this->assertFalse($field['hasDefault']);
        $this->assertFalse($field['exclude']);
        $this->assertNull($field['source']);
    }

    public function testNullableFieldFlags(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['nickname'];

        $this->assertSame('string', $field['type']);
        $this->assertTrue($field['nullable']);
        $this->assertFalse($field['required']);
        $this->assertNull($field['requiredIf']);
    }

    public function testDefaultValueFieldFlags(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['page'];

        $this->assertSame('int', $field['type']);
        $this->assertTrue($field['hasDefault']);
        $this->assertFalse($field['required']);
    }

    public function testExcludedFieldIsEmittedWithExcludeFlag(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertArrayHasKey('internal', $schema, 'excluded fields must still be present in the schema');
        $this->assertTrue($schema['internal']['exclude']);
        $this->assertFalse($schema['name']['exclude']);
    }

    public function testFromRouteFieldExposesSource(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['id'];

        $this->assertSame('route', $field['source']);
        $this->assertSame('int', $field['type']);
    }

    public function testFromContextFieldExposesSource(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['contextId'];

        $this->assertSame('context', $field['source']);
        $this->assertSame('int', $field['type']);
    }

    public function testFromBodyFieldExposesSource(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['bodyField'];

        $this->assertSame('body', $field['source']);
        $this->assertSame('string', $field['type']);
    }

    public function testQuerySourceMapsToString(): void
    {
        // No source attribute yields FieldSource::Query — Query is the default
        // bag for handleQuery() and is never stored on a property. Build the
        // metadata directly to exercise the Query → 'query' mapping.
        $metadata = new RequestMetadata(
            className: SchemaFixtureRequest::class,
            properties: ['q' => new PropertyMetadata(
                name: 'q',
                source: FieldSource::Query,
                sourceKey: 'q',
                type: 'string',
                isNullable: false,
                hasDefault: false,
                defaultValue: null,
                validationRules: null,
                castType: null,
                effectiveCastType: null,
                customCasterClass: null,
                preProcessor: null,
                preProcessorKind: null,
                postProcessor: null,
                postProcessorKind: null,
                postProcessConfig: [],
                group: null,
                isRequired: false,
                reflection: new ReflectionProperty(SchemaFixtureRequest::class, 'name'),
            )],
            reflection: new ReflectionClass(SchemaFixtureRequest::class),
        );

        $schema = (new SchemaBuilder())->build($metadata);

        $this->assertSame('query', $schema['q']['source']);
    }

    // ===== requiredIf extraction =====

    public function testRequiredIfExtractsFieldAndValue(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['companyName'];

        $this->assertSame(
            ['field' => 'company_type', 'value' => 'company'],
            $field['requiredIf'],
        );
        // required_if does NOT set the literal `required` flag.
        $this->assertFalse($field['required']);
    }

    public function testRequiredIfNullWhenAbsent(): void
    {
        $this->assertNull($this->build(SchemaFixtureRequest::class)['name']['requiredIf']);
    }

    public function testMalformedRequiredIfTreatedAsAbsent(): void
    {
        // `required_if` with only one argument is not a well-formed conditional:
        // requiredIf stays null, but the raw rule still appears in `rules`.
        $field = $this->build(SchemaFixtureRequest::class)['conditionalBad'];

        $this->assertNull($field['requiredIf']);
        $this->assertContains(['name' => 'required_if', 'args' => ['onlyfield']], $field['rules']);
    }

    // ===== rule normalization =====

    public function testMaxRuleArgs(): void
    {
        $rules = $this->build(SchemaFixtureRequest::class)['nickname']['rules'];

        $this->assertContains(['name' => 'nullable', 'args' => []], $rules);
        $this->assertContains(['name' => 'string', 'args' => []], $rules);
        $this->assertContains(['name' => 'max', 'args' => ['255']], $rules);
    }

    public function testInRuleSplitsAllArgs(): void
    {
        $rules = $this->build(SchemaFixtureRequest::class)['status']['rules'];

        $this->assertContains(['name' => 'in', 'args' => ['a', 'b']], $rules);
    }

    public function testRequiredIfRuleKeepsAllArgsInList(): void
    {
        $rules = $this->build(SchemaFixtureRequest::class)['companyName']['rules'];

        $this->assertContains(['name' => 'required_if', 'args' => ['company_type', 'company']], $rules);
    }

    public function testNullableArrayWithItemsRule(): void
    {
        $field = $this->build(SchemaFixtureRequest::class)['lines'];

        $this->assertSame('array', $field['type']);
        $this->assertTrue($field['nullable']);
        $this->assertContains(['name' => 'array', 'args' => []], $field['rules']);
    }

    public function testFieldWithoutRulesHasEmptyRuleList(): void
    {
        // `id` has only #[Validate('required|integer')]; a property with no
        // Validate attribute yields an empty rule list.
        $field = $this->build(SchemaFixtureRequest::class)['noRules'];

        $this->assertSame([], $field['rules']);
        $this->assertNull($field['requiredIf']);
    }

    // ===== completeness =====

    public function testAllManagedPropertiesAreIncluded(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $this->assertSame(
            [
                'id', 'name', 'nickname', 'companyName', 'status', 'page',
                'internal', 'lines', 'noRules', 'contextId', 'bodyField', 'conditionalBad',
            ],
            array_keys($schema),
        );
    }

    // ===== JSON serialization =====

    public function testSchemaIsLosslesslyJsonSerializable(): void
    {
        $schema = $this->build(SchemaFixtureRequest::class);

        $json = json_encode($schema);
        $this->assertNotFalse($json);

        $roundTrip = json_decode($json, true);
        $this->assertSame($schema, $roundTrip);
    }

    // ===== via RequestHandler::schema() =====

    public function testRequestHandlerSchemaWrapperMatchesBuilder(): void
    {
        // schema() is read-only and never invokes the validator, so a stub
        // (no configured expectations) is the correct double here.
        $validator = $this->createStub(ValidatorInterface::class);
        $handler = new RequestHandler($validator);

        $viaHandler = $handler->schema(SchemaFixtureRequest::class);
        $viaBuilder = $this->build(SchemaFixtureRequest::class);

        $this->assertSame($viaBuilder, $viaHandler);

        $requiredIf = $viaHandler['companyName']['requiredIf'];
        $this->assertNotNull($requiredIf);
        $this->assertSame('company_type', $requiredIf['field']);
        $this->assertNotFalse(json_encode($viaHandler));
    }
}

// ============================================================================
// Fixtures
// ============================================================================

final class SchemaFixtureRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[Validate('required|string')]
    public string $name;

    #[Validate('nullable|string|max:255')]
    public ?string $nickname = null;

    #[Validate('nullable|string|required_if:company_type,company')]
    public ?string $companyName = null;

    #[Validate('nullable|in:a,b')]
    public ?string $status = null;

    #[Validate('integer|min:1')]
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

    #[FromBody('shipping.zip')]
    public ?string $bodyField = null;

    #[Validate('nullable|required_if:onlyfield')]
    public ?string $conditionalBad = null;
}

final class SchemaLineRequest extends Request
{
    #[Validate('required|string')]
    public string $sku;
}
