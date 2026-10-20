<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Cast;
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Attributes\DateFormat;
use Solo\RequestHandler\Attributes\Generator;
use Solo\RequestHandler\Attributes\Items;
use Solo\RequestHandler\Attributes\PostProcess;
use Solo\RequestHandler\Attributes\Source\FromContext;
use Solo\RequestHandler\Cache\MetadataBuilder;
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Contracts\PostProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\ProcessContext;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\Tests\ExpectsExceptionMessageParts;

final class MetadataBuilderTest extends TestCase
{
    use ExpectsExceptionMessageParts;

    private MetadataBuilder $builder;

    protected function setUp(): void
    {
        // Child traversal is covered through ReflectionCache in MetadataTraversalTest.
        $this->builder = new MetadataBuilder(static function (string $child): void {
        });
    }

    public function testChildFailurePrecedesRemainingParentChecks(): void
    {
        $failure = new \RuntimeException('Child loading failed');
        $builder = new MetadataBuilder(static function (string $child) use ($failure): void {
            throw $failure;
        });

        try {
            $builder->build(BuilderInvalidItemsRequest::class);
            $this->fail('Expected the child loading exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(ConfigurationException::itemsRequiresArrayType(
            BuilderInvalidItemsRequest::class,
            'items',
            'string',
        )->getMessage());
        $this->builder->build(BuilderInvalidItemsRequest::class);
    }

    public function testUntypedPropertyIsNullable(): void
    {
        $property = $this->builder->build(UntypedPropertyRequest::class)->properties['data'];

        $this->assertNull($property->type);
        $this->assertTrue($property->isNullable);
    }

    public function testAttributesOfOtherLibrariesAreLeftAlone(): void
    {
        // Never instantiated: the class does not even have to exist.
        $property = $this->builder->build(ForeignAttributeRequest::class)->properties['name'];

        $this->assertSame('string', $property->type);
    }

    public function testOnlyValuesSomethingElseProducesGetAResultGuard(): void
    {
        $properties = $this->builder->build(ResultGuardRequest::class)->properties;
        $guarded = array_keys(array_filter(
            $properties,
            static fn($property) => $property->resultGuard !== null,
        ));

        // Built-in decoders and child construction already yield the declared
        // type: a guard there only slows metadata building down.
        $this->assertSame(['money', 'code', 'userId', 'ref'], $guarded);
    }

    public function testDateFormatIsStoredWithItsFailureRule(): void
    {
        $properties = $this->builder->build(DateTypesRequest::class)->properties;

        $this->assertSame('Y-m-d H:i:s', $properties['formatted']->dateFormat);
        $this->assertSame(
            ['rule' => 'date_format', 'params' => ['Y-m-d H:i:s']],
            $properties['formatted']->castFailure,
        );
        $this->assertSame(['rule' => 'date'], $properties['mutable']->castFailure);
    }

    /** @return iterable<string, non-empty-list<string>> */
    public static function misconfigurations(): iterable
    {
        yield 'abstract #[Items] class' => [AbstractItemsRequest::class, 'abstract'];
        yield 'missing #[Items] class' => [NonExistentItemsRequest::class, 'class does not exist'];
        yield '#[Items] class not a Request' => [NotRequestItemsRequest::class, 'must extend Request'];
        yield 'cast type mismatch' => [
            InvalidCastRequest::class,
            "cast type 'string' which is incompatible with property type 'int'",
        ];
        yield 'missing caster class' => [CasterWithNonExistentClassRequest::class, 'invalid #[Caster('];
        yield 'not a generator' => [InvalidGeneratorRequest::class, 'invalid #[Generator('];
        yield 'type no input can hold' => [
            IntersectionTypedRequest::class,
            '::$items has type',
            'cannot be produced from request input',
        ];
        yield '#[DateFormat] on a string' => [DateFormatOnStringRequest::class, "has #[DateFormat] but type 'string'"];
        yield '#[DateFormat] with a caster' => [
            DateFormatWithCasterRequest::class,
            'has both #[DateFormat] and #[Caster]',
        ];
        yield 'misplaced library attribute' => [
            MisplacedAttributeRequest::class,
            'MisplacedAttributeRequest::$createdBy has',
            '#[Solo\RequestHandler\Attributes\FromContext], which is not a RequestHandler property attribute.',
            'Did you mean #[Solo\RequestHandler\Attributes\Source\FromContext]?',
        ];
        yield 'misspelled class attribute' => [
            MisspelledClassAttributeRequest::class,
            'Class ' . MisspelledClassAttributeRequest::class . ' has #[Solo\RequestHandler\Attributes\NoTrimm], '
                . 'which is not a RequestHandler class attribute.',
        ];
    }

    /** @param class-string $class */
    #[DataProvider('misconfigurations')]
    public function testMisconfigurationFailsTheBuild(string $class, string ...$parts): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageParts(...$parts);
        $this->builder->build($class);
    }
}

final class UntypedPropertyRequest extends Request
{
    /** @var mixed */
    public $data;
}

final class ForeignAttributeRequest extends Request
{
    /** @phpstan-ignore attribute.notFound (another library's attribute, never loaded) */
    #[\Vendor\Orm\Column(length: 64)]
    public ?string $name = null;
}

final class ResultGuardRequest extends Request
{
    public int $count = 0;
    /** @var list<string> */
    public array $tags = [];
    public ?\DateTimeImmutable $at = null;
    public ?BuilderItemRequest $child = null;
    #[Caster(DummyCaster::class)]
    public string $money = '';
    #[PostProcess(GuardedPostProcessor::class)]
    public string $code = '';
    #[FromContext('userId')]
    public int $userId = 0;
    public int|string $ref = 0;
}

/** @implements PostProcessorInterface<string> */
final class GuardedPostProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): string
    {
        return (string) $value;
    }
}

final class InvalidCastRequest extends Request
{
    #[Cast('string')]
    public int $id;
}

final class CasterWithNonExistentClassRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not exist */
    #[Caster('NonExistentCasterClass')]
    public string $value;
}

/** @implements CasterInterface<mixed> */
final class DummyCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        return $value;
    }
}

final class InvalidGeneratorRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not implement GeneratorInterface */
    #[Generator(NotAGenerator::class)]
    public string $id;
}

final class NotAGenerator
{
    public function generate(): string
    {
        return 'not_a_generator';
    }
}

final class NonExistentItemsRequest extends Request
{
    /**
     * @var array<mixed>|null
     * @phpstan-ignore-next-line — intentional: class does not exist
     */
    #[Items('NonExistentItemClass')]
    public ?array $items = null;
}

final class NotRequestItemsRequest extends Request
{
    /**
     * @var array<mixed>|null
     * @phpstan-ignore-next-line — intentional: class does not extend Request
     */
    #[Items(NotAGenerator::class)]
    public ?array $items = null;
}

abstract class AbstractItemRequest extends Request
{
    public string $product;
}

final class AbstractItemsRequest extends Request
{
    /** @var array<int, array<string, mixed>>|null */
    #[Items(AbstractItemRequest::class)]
    public ?array $items = null;
}

final class MisplacedAttributeRequest extends Request
{
    /** @phpstan-ignore attribute.notFound (source attributes live in Attributes\Source) */
    #[\Solo\RequestHandler\Attributes\FromContext('authUserId')]
    public int $createdBy = 0;
}

/** @phpstan-ignore attribute.notFound (intentionally misspelled) */
#[\Solo\RequestHandler\Attributes\NoTrimm]
final class MisspelledClassAttributeRequest extends Request
{
    public ?string $name = null;
}

final class DateTypesRequest extends Request
{
    public \DateTime $mutable;

    #[DateFormat('Y-m-d')]
    public mixed $anything = null;

    #[DateFormat('Y-m-d H:i:s')]
    public \DateTime $formatted;
}

final class DateFormatOnStringRequest extends Request
{
    #[DateFormat('Y-m-d')]
    public string $day;
}

final class DateFormatWithCasterRequest extends Request
{
    #[DateFormat('Y-m-d')]
    #[Caster(DummyCaster::class)]
    public \DateTimeImmutable $day;
}

final class IntersectionTypedRequest extends Request
{
    /** @var \Countable&\ArrayAccess<int, mixed> */
    public \Countable&\ArrayAccess $items;
}

final class BuilderItemRequest extends Request
{
    public int $quantity = 0;
}

final class BuilderInvalidItemsRequest extends Request
{
    #[Items(BuilderItemRequest::class)]
    public string $items;
}
