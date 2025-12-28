<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Request;
use Solo\RequestHandler\Exceptions\ConfigurationException;

final class ReflectionCacheTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    public function testGetReturnsCachedMetadata(): void
    {
        $metadata1 = $this->cache->get(ValidRequest::class);
        $metadata2 = $this->cache->get(ValidRequest::class);

        $this->assertSame($metadata1, $metadata2);
    }

    public function testClearRemovesAllCache(): void
    {
        $metadata1 = $this->cache->get(ValidRequest::class);
        $this->cache->clear();
        $metadata2 = $this->cache->get(ValidRequest::class);

        $this->assertNotSame($metadata1, $metadata2);
    }

    public function testBuildPropertyMetadataIncludesGroup(): void
    {
        $metadata = $this->cache->get(GroupedRequest::class);

        $this->assertEquals('criteria', $metadata->properties['search']->group);
        $this->assertEquals('criteria', $metadata->properties['status']->group);
        $this->assertNull($metadata->properties['page']->group);
    }

    public function testStaticPropertiesAreIgnored(): void
    {
        $metadata = $this->cache->get(StaticPropertyRequest::class);

        $this->assertArrayHasKey('name', $metadata->properties);
        $this->assertArrayNotHasKey('counter', $metadata->properties);
    }

    public function testPropertyWithoutTypeIsNullable(): void
    {
        $metadata = $this->cache->get(UntypedPropertyRequest::class);

        $this->assertNull($metadata->properties['data']->type);
        $this->assertTrue($metadata->properties['data']->isNullable);
        $this->assertFalse($metadata->properties['data']->isRequired);
    }

    public function testIsRequiredCalculation(): void
    {
        $metadata = $this->cache->get(RequiredTestRequest::class);

        // Required: has 'required' in rules
        $this->assertTrue($metadata->properties['required']->isRequired);

        // Not required: no 'required' in rules
        $this->assertFalse($metadata->properties['optional']->isRequired);

        // Required nullable: has 'required' in rules
        $this->assertTrue($metadata->properties['requiredNullable']->isRequired);

        // Not required: no rules at all
        $this->assertFalse($metadata->properties['noRules']->isRequired);
    }

    public function testNullableRuleWithNonNullableTypeThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has 'nullable' in validation rules but type 'string' doesn't allow null");

        $this->cache->get(InvalidNullableRequest::class);
    }

    public function testCastTypeMismatchThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("cast type 'string' which is incompatible with property type 'int'");

        $this->cache->get(InvalidCastRequest::class);
    }

    public function testRequiredWithDefaultThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has 'required' in rules but also has a default value");

        $this->cache->get(InvalidRequiredRequest::class);
    }

    public function testNullableTypeWithNullableRuleIsValid(): void
    {
        // This is a VALID configuration - no exceptions should be thrown
        $metadata = $this->cache->get(ValidNullableRequest::class);

        $this->assertTrue($metadata->properties['email']->isNullable);
    }

    public function testUnionTypesWithCompatibleCast(): void
    {
        // Union type is compatible if at least one type matches
        $metadata = $this->cache->get(UnionTypeRequest::class);

        $this->assertArrayHasKey('price', $metadata->properties);
        $this->assertEquals('float', $metadata->properties['price']->castType);
    }

    public function testUnionTypesWithIncompatibleCastThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("cast type 'bool' which is incompatible with property type 'int|float'");

        $this->cache->get(InvalidUnionTypeRequest::class);
    }

    public function testUnknownCastTypeIsAllowed(): void
    {
        // Unknown cast types (not in built-in map) should be allowed
        $metadata = $this->cache->get(UnknownCastTypeRequest::class);

        $this->assertEquals('unknown_type', $metadata->properties['value']->castType);
    }

    public function testDateTimeCastWithDateTimeInterface(): void
    {
        // DateTime cast should be compatible with DateTimeInterface
        $metadata = $this->cache->get(DateTimeInterfaceRequest::class);

        $this->assertEquals('datetime', $metadata->properties['date']->castType);
        $this->assertEquals('DateTimeInterface', $metadata->properties['date']->type);
    }

    public function testDateTimeImmutableCastWithDateTimeInterface(): void
    {
        // DateTime immutable cast should be compatible with DateTimeInterface
        $metadata = $this->cache->get(DateTimeImmutableInterfaceRequest::class);

        $this->assertEquals('datetime:immutable', $metadata->properties['date']->castType);
        $this->assertEquals('DateTimeInterface', $metadata->properties['date']->type);
    }

    public function testCustomCasterClassIsAllowed(): void
    {
        // Custom caster classes should bypass type validation
        $metadata = $this->cache->get(CustomCasterRequest::class);

        $this->assertEquals(DummyCaster::class, $metadata->properties['value']->castType);
        $this->assertEquals('string', $metadata->properties['value']->type);
    }

    public function testDateTimeCastWithDateTime(): void
    {
        // DateTime cast should be compatible with DateTime class
        $metadata = $this->cache->get(DateTimeRequest::class);

        $this->assertEquals('datetime:Y-m-d H:i:s', $metadata->properties['createdAt']->castType);
        $this->assertEquals('DateTime', $metadata->properties['createdAt']->type);
    }

    public function testArrayCastWithArrayType(): void
    {
        // Array cast should be compatible with array type
        $metadata = $this->cache->get(ArrayCastRequest::class);

        $this->assertEquals('array', $metadata->properties['tags']->castType);
        $this->assertEquals('array', $metadata->properties['tags']->type);
    }

    public function testInvalidPreProcessorThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has invalid preProcess 'nonExistentFunction'");

        $this->cache->get(InvalidPreProcessorRequest::class);
    }

    public function testInvalidPostProcessorThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has invalid postProcess 'typoFunction'");

        $this->cache->get(InvalidPostProcessorRequest::class);
    }

    public function testClassWithoutInterfaceThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has invalid preProcess");

        $this->cache->get(InvalidClassProcessorRequest::class);
    }

    public function testUuidFieldMetadata(): void
    {
        $metadata = $this->cache->get(UuidFieldRequest::class);

        $this->assertTrue($metadata->properties['id']->uuid);
        $this->assertFalse($metadata->properties['name']->uuid);
    }

    public function testUuidWithNonStringTypeThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has 'uuid: true' but type is 'int'");

        $this->cache->get(InvalidUuidTypeRequest::class);
    }

    public function testExcludeFieldMetadata(): void
    {
        $metadata = $this->cache->get(ExcludeFieldRequest::class);

        $this->assertTrue($metadata->properties['excluded']->exclude);
        $this->assertFalse($metadata->properties['normal']->exclude);
    }
}

final class ValidRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;
}

final class GroupedRequest extends Request
{
    #[Field(rules: 'nullable|string', group: 'criteria')]
    public ?string $search = null;

    #[Field(rules: 'nullable|string', group: 'criteria')]
    public ?string $status = null;

    #[Field(rules: 'integer|min:1', cast: 'int')]
    public int $page = 1;
}

final class StaticPropertyRequest extends Request
{
    public static int $counter = 0;
    public string $name;
}

final class UntypedPropertyRequest extends Request
{
    /** @var mixed */
    public $data;
}

final class RequiredTestRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $required;

    #[Field(rules: 'nullable|string')]
    public ?string $optional = null;

    #[Field(rules: 'required|nullable|string')]
    public ?string $requiredNullable;  // No default - required fields can't have defaults

    public string $noRules;
}

// ❌ INVALID configuration: nullable rule with non-nullable type
final class InvalidNullableRequest extends Request
{
    #[Field(rules: 'nullable|email')]
    public string $email;  // Type doesn't allow null
}

// ❌ INVALID configuration: cast type mismatch
final class InvalidCastRequest extends Request
{
    #[Field(cast: 'string')]
    public int $id;  // Casting to string but property is int
}

// ❌ INVALID configuration: required with default
final class InvalidRequiredRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name = 'default';  // Required shouldn't have default
}

// ✅ VALID configuration: nullable type with nullable rule
final class ValidNullableRequest extends Request
{
    #[Field(rules: 'nullable|email')]
    public ?string $email = null;  // Correct: type allows null
}

// ✅ VALID configuration: union types with compatible cast
final class UnionTypeRequest extends Request
{
    #[Field(rules: 'numeric', cast: 'float')]
    public int|float $price;  // Valid: float cast is compatible with int|float
}

// ❌ INVALID configuration: union types with incompatible cast
final class InvalidUnionTypeRequest extends Request
{
    #[Field(cast: 'bool')]
    public int|float $count;  // Invalid: bool is not compatible with int|float
}

final class UnknownCastTypeRequest extends Request
{
    #[Field(cast: 'unknown_type')]
    public string $value;
}

final class DateTimeInterfaceRequest extends Request
{
    #[Field(cast: 'datetime')]
    public \DateTimeInterface $date;
}

final class DateTimeImmutableInterfaceRequest extends Request
{
    #[Field(cast: 'datetime:immutable')]
    public \DateTimeInterface $date;
}

final class CustomCasterRequest extends Request
{
    #[Field(cast: DummyCaster::class)]
    public string $value;
}

final class DateTimeRequest extends Request
{
    #[Field(cast: 'datetime:Y-m-d H:i:s')]
    public \DateTime $createdAt;
}

final class ArrayCastRequest extends Request
{
    /** @var array<mixed> */
    #[Field(cast: 'array')]
    public array $tags;
}

// Dummy caster for testing custom caster compatibility
final class DummyCaster implements \Solo\RequestHandler\Casters\CasterInterface
{
    public function cast(mixed $value): mixed
    {
        return $value;
    }
}

// ❌ INVALID: non-existent preProcessor
final class InvalidPreProcessorRequest extends Request
{
    #[Field(preProcess: 'nonExistentFunction')]
    public string $name;
}

// ❌ INVALID: non-existent postProcessor
final class InvalidPostProcessorRequest extends Request
{
    #[Field(postProcess: 'typoFunction')]
    public string $value;
}

// ❌ INVALID: class without interface
final class InvalidClassProcessorRequest extends Request
{
    #[Field(preProcess: ClassWithoutInterface::class)]
    public string $data;
}

// Helper class without interface for testing
final class ClassWithoutInterface
{
    public function process(mixed $value): mixed
    {
        return $value;
    }
}

// ✅ VALID: uuid field
final class UuidFieldRequest extends Request
{
    #[Field(uuid: true)]
    public string $id;

    #[Field(rules: 'required|string')]
    public string $name;
}

// ❌ INVALID: uuid with non-string type
final class InvalidUuidTypeRequest extends Request
{
    #[Field(uuid: true)]
    public int $id;
}

// ✅ VALID: exclude field
final class ExcludeFieldRequest extends Request
{
    #[Field(exclude: true)]
    public string $excluded = 'default';

    #[Field(rules: 'required|string')]
    public string $normal;
}
