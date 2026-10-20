<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\DateFormat;
use Solo\RequestHandler\Attributes\EmptyToNull;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\NoTrim;
use Solo\RequestHandler\Attributes\Source\FromBody;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Request;
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

    // ===== input hints and shared keys =====

    public function testInputHandlingTheTypeDoesNotShowIsReported(): void
    {
        $schema = $this->build(SchemaInputHintsRequest::class);

        $this->assertSame('json', $schema['settings']['cast']);
        $this->assertSame('string', $schema['settings']['type']);
        $this->assertSame('float', $schema['amount']['cast']);
        $this->assertTrue($schema['note']['emptyToNull']);
        $this->assertTrue($schema['snippet']['noTrim']);

        foreach (['cast', 'emptyToNull', 'noTrim'] as $key) {
            $this->assertArrayNotHasKey($key, $schema['plain'], "{$key}: an int property cast as int says nothing new");
        }
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

final class SchemaInputHintsRequest extends Request
{
    #[Cast('json')]
    public ?string $settings = null;

    #[Cast('float')]
    public int|float $amount = 0;

    #[EmptyToNull]
    public ?string $note = null;

    #[NoTrim]
    public string $snippet = '';

    public int $plain = 0;
}
