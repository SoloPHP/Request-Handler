<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Cache;

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
use Solo\RequestHandler\Cache\ProcessorKind;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Contracts\GeneratorInterface;
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\Exceptions\ConfigurationException;
use Solo\RequestHandler\FieldSource;
use Solo\RequestHandler\ProcessContext;
use Solo\RequestHandler\Request;

final class ReflectionCacheTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    // ===== caching =====

    public function testGetReturnsCachedMetadata(): void
    {
        $a = $this->cache->get(ValidRequest::class);
        $b = $this->cache->get(ValidRequest::class);
        $this->assertSame($a, $b);
    }

    public function testSharedRegistryIsOneInstanceAndClearable(): void
    {
        $this->assertSame(ReflectionCache::shared(), ReflectionCache::shared());

        $before = ReflectionCache::shared()->get(ValidRequest::class);
        ReflectionCache::shared()->clear(ValidRequest::class);

        $this->assertNotSame($before, ReflectionCache::shared()->get(ValidRequest::class));
    }

    public function testGroupMembershipIsPrecomputed(): void
    {
        $metadata = $this->cache->get(GroupedRequest::class);

        $this->assertSame(['criteria' => ['search', 'status']], $metadata->groups);
    }

    // ===== property metadata =====

    public function testStaticPropertiesAreIgnored(): void
    {
        $metadata = $this->cache->get(StaticPropertyRequest::class);

        $this->assertArrayHasKey('name', $metadata->properties);
        $this->assertArrayNotHasKey('counter', $metadata->properties);
    }

    public function testProtectedAndPrivatePropertiesAreIgnored(): void
    {
        $metadata = $this->cache->get(VisibilityRequest::class);

        $this->assertArrayHasKey('publicField', $metadata->properties);
        $this->assertArrayNotHasKey('protectedField', $metadata->properties);
        $this->assertArrayNotHasKey('privateField', $metadata->properties);
    }

    public function testIgnoredPropertyIsSkippedFromMetadata(): void
    {
        $metadata = $this->cache->get(IgnoredFieldRequest::class);

        $this->assertArrayHasKey('name', $metadata->properties);
        $this->assertArrayNotHasKey('currentUser', $metadata->properties);
    }

    #[DataProvider('ignoreConflictProvider')]
    public function testIgnoreWithCompanionAttributeThrows(string $requestClass, string $expectedAttr): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[Ignore]');
        $this->expectExceptionMessage("#[{$expectedAttr}]");
        /** @var class-string $requestClass */
        $this->cache->get($requestClass);
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function ignoreConflictProvider(): array
    {
        // Representative cases across the three attribute families:
        // pipeline (Validate), source (FromBody), generation (Generator).
        return [
            'Validate'  => [IgnoreWithValidateRequest::class, 'Validate'],
            'FromBody'  => [IgnoreWithFromBodyRequest::class, 'FromBody'],
            'Generator' => [IgnoreWithGeneratorRequest::class, 'Generator'],
            'EmptyToNull' => [IgnoreWithEmptyToNullRequest::class, 'EmptyToNull'],
            'NoTrim' => [IgnoreWithNoTrimRequest::class, 'NoTrim'],
        ];
    }

    // ===== #[NoTrim] =====

    public function testNoTrimOnAPropertyOrAClass(): void
    {
        $properties = $this->cache->get(NoTrimPropertyRequest::class)->properties;
        $this->assertTrue($properties['snippet']->noTrim);
        $this->assertFalse($properties['title']->noTrim);

        $properties = $this->cache->get(NoTrimChildRequest::class)->properties;
        $this->assertTrue($properties['body']->noTrim, 'inherited from the parent class');
        $this->assertTrue($properties['extra']->noTrim);
    }

    public function testNoTrimWithGeneratorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[Generator] and #[NoTrim]');
        $this->cache->get(GeneratorWithNoTrimRequest::class);
    }

    // ===== #[EmptyToNull] =====

    public function testEmptyToNullOnAProperty(): void
    {
        $metadata = $this->cache->get(EmptyToNullPropertyRequest::class);

        $this->assertTrue($metadata->properties['note']->emptyToNull);
        $this->assertFalse($metadata->properties['title']->emptyToNull);
    }

    public function testEmptyToNullOnAClassCoversEveryInputPropertyAndIsInherited(): void
    {
        foreach ([EmptyToNullBaseRequest::class, EmptyToNullChildRequest::class] as $class) {
            $properties = $this->cache->get($class)->properties;

            $this->assertTrue($properties['note']->emptyToNull, $class);
            $this->assertFalse($properties['id']->emptyToNull, "{$class}: generated, never read");
        }
        $this->assertTrue($this->cache->get(EmptyToNullChildRequest::class)->properties['extra']->emptyToNull);
    }

    public function testContextValuesAreKeptAsTheApplicationPassesThem(): void
    {
        $properties = $this->cache->get(ClientInputClassRequest::class)->properties;

        $this->assertTrue($properties['note']->emptyToNull);
        $this->assertTrue($properties['note']->noTrim);
        $this->assertFalse($properties['tenant']->emptyToNull, 'a class-level #[EmptyToNull] leaves context out');
        $this->assertTrue($properties['tenant']->noTrim, 'auto-trim never touches a context value');
        $this->assertTrue($properties['explicit']->emptyToNull, 'on the property itself it still applies');

        $this->assertTrue($this->cache->get(ContextDtoRequest::class)->properties['address']->noTrim);
    }

    public function testRequestTypedContextPropertyIsNotNested(): void
    {
        $this->assertNull($this->cache->get(ContextDtoRequest::class)->properties['address']->nested);
    }

    public function testEmptyToNullWithGeneratorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[Generator] and #[EmptyToNull]');
        $this->cache->get(GeneratorWithEmptyToNullRequest::class);
    }

    public function testGroupMetadata(): void
    {
        $metadata = $this->cache->get(GroupedRequest::class);

        $this->assertSame('criteria', $metadata->properties['search']->group);
        $this->assertSame('criteria', $metadata->properties['status']->group);
        $this->assertNull($metadata->properties['page']->group);
    }

    public function testExcludeMetadata(): void
    {
        $metadata = $this->cache->get(ExcludeFieldRequest::class);

        $this->assertTrue($metadata->properties['excluded']->exclude);
        $this->assertFalse($metadata->properties['normal']->exclude);
    }

    public function testIsRequiredCalculation(): void
    {
        $metadata = $this->cache->get(RequiredTestRequest::class);

        $this->assertTrue($metadata->properties['required']->isRequired);
        $this->assertFalse($metadata->properties['optional']->isRequired);
        $this->assertTrue($metadata->properties['requiredNullable']->isRequired);
        $this->assertFalse($metadata->properties['noRules']->isRequired);
    }

    public function testValidatesWhenAbsentCalculation(): void
    {
        $metadata = $this->cache->get(ConditionalPresenceRequest::class);

        // Literal 'required' sets both flags.
        $this->assertTrue($metadata->properties['plainRequired']->isRequired);
        $this->assertTrue($metadata->properties['plainRequired']->validatesWhenAbsent);

        // 'required_if' is NOT literal required — but it does validate when absent.
        $this->assertFalse($metadata->properties['conditionallyRequired']->isRequired);
        $this->assertTrue($metadata->properties['conditionallyRequired']->validatesWhenAbsent);

        // No presence rule at all — neither flag.
        $this->assertFalse($metadata->properties['plainOptional']->isRequired);
        $this->assertFalse($metadata->properties['plainOptional']->validatesWhenAbsent);
    }

    public function testRequiredIfWithDefaultDoesNotThrow(): void
    {
        // requiredWithDefault guards LITERAL 'required' only; a conditional
        // required_if with a default is the intended usage pattern.
        $metadata = $this->cache->get(ConditionalPresenceRequest::class);

        $this->assertTrue($metadata->properties['conditionallyRequired']->hasDefault);
        $this->assertTrue($metadata->properties['conditionallyRequired']->validatesWhenAbsent);
    }

    public function testHasRuleDoesNotMatchInsideValueList(): void
    {
        // 'in:nullable,active' must NOT trigger nullable-rule detection.
        // If it did, the build would throw nullableRuleWithNonNullableType.
        $metadata = $this->cache->get(InRuleWithNullableLiteralRequest::class);

        $this->assertSame('in:nullable,active', $metadata->properties['status']->validationRules);
        $this->assertFalse($metadata->properties['status']->isRequired);
    }

    public function testHasRuleMatchesPipeSeparatedRequired(): void
    {
        // 'required|in:active,nullable' — 'required' IS a real rule; 'nullable' inside in: is NOT.
        $metadata = $this->cache->get(RequiredInRuleRequest::class);

        $this->assertTrue($metadata->properties['status']->isRequired);
    }

    public function testUntypedPropertyIsNullable(): void
    {
        $metadata = $this->cache->get(UntypedPropertyRequest::class);

        $this->assertNull($metadata->properties['data']->type);
        $this->assertTrue($metadata->properties['data']->isNullable);
    }

    // ===== source detection =====

    public function testDefaultSourceIsNullWhenNoAttribute(): void
    {
        $metadata = $this->cache->get(ValidRequest::class);

        $this->assertNull($metadata->properties['name']->source);
        $this->assertSame('name', $metadata->properties['name']->sourceKey);
    }

    public function testFromRouteUsesPropertyNameByDefault(): void
    {
        $metadata = $this->cache->get(FromRouteImplicitRequest::class);

        $this->assertSame(FieldSource::Route, $metadata->properties['id']->source);
        $this->assertSame('id', $metadata->properties['id']->sourceKey);
    }

    public function testFromContextUsesExplicitKey(): void
    {
        $metadata = $this->cache->get(FromContextKeyRequest::class);

        $this->assertSame(FieldSource::Context, $metadata->properties['userId']->source);
        $this->assertSame('authUserId', $metadata->properties['userId']->sourceKey);
    }

    public function testMultipleSourcesThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple source attributes');
        $this->cache->get(MultipleSourcesRequest::class);
    }

    public function testFromBodyStoresPathSegments(): void
    {
        $metadata = $this->cache->get(FromBodyNestedRequest::class);

        $this->assertSame(FieldSource::Body, $metadata->properties['customerId']->source);
        $this->assertSame('customer.id', $metadata->properties['customerId']->sourceKey);
        $this->assertSame(['customer', 'id'], $metadata->properties['customerId']->pathSegments);
    }

    public function testFromBodySingleSegmentBehavesLikeFlatKeyRemap(): void
    {
        $metadata = $this->cache->get(FromBodyFlatKeyRequest::class);

        $this->assertSame(FieldSource::Body, $metadata->properties['name']->source);
        $this->assertSame(['display_name'], $metadata->properties['name']->pathSegments);
    }

    public function testFromBodyCombinedWithFromRouteThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple source attributes');
        $this->cache->get(FromBodyAndFromRouteRequest::class);
    }

    public function testFromBodyCombinedWithFromContextThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple source attributes');
        $this->cache->get(FromBodyAndFromContextRequest::class);
    }

    public function testMultipleSourcesMessageListsAllDeclaredAttributes(): void
    {
        // F7: when all three source attributes are declared, the error must
        // name all three so the user can fix in one pass.
        try {
            $this->cache->get(ThreeSourcesRequest::class);
            $this->fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('#[FromRoute]', $msg);
            $this->assertStringContainsString('#[FromContext]', $msg);
            $this->assertStringContainsString('#[FromBody]', $msg);
        }
    }

    // ===== F6: ambiguous processor =====

    public function testAmbiguousProcessorThrows(): void
    {
        // F6: method name 'trim' shadowed by PHP built-in — fail loudly rather
        // than silently dispatch to one of them.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ambiguous');
        $this->cache->get(AmbiguousTrimProcessorRequest::class);
    }

    // ===== F9: abstract Items / Generator rejected at build =====

    public function testAbstractItemsClassRejectedAtBuild(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('abstract');
        $this->cache->get(AbstractItemsRequest::class);
    }

    public function testAbstractGeneratorClassRejectedAtBuild(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('abstract');
        $this->cache->get(AbstractGeneratorRequest::class);
    }

    // ===== F12: intersection types =====

    public function testIntersectionTypeRecognisedAsNonNullable(): void
    {
        $metadata = $this->cache->get(IntersectionTypeRequest::class);
        $prop = $metadata->properties['thing'];
        $this->assertSame('Countable&Iterator', $prop->type);
        $this->assertFalse($prop->isNullable);
    }

    // ===== conflict guards =====

    public function testNullableRuleWithNonNullableTypeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'nullable' in validation rules but type 'string' doesn't allow null");
        $this->cache->get(InvalidNullableRequest::class);
    }

    public function testCastTypeMismatchThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("cast type 'string' which is incompatible with property type 'int'");
        $this->cache->get(InvalidCastRequest::class);
    }

    public function testRequiredWithDefaultThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has 'required' in rules but also has a default value");
        $this->cache->get(InvalidRequiredRequest::class);
    }

    public function testCastWithClassNameThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[Cast');
        $this->expectExceptionMessage('class name');
        $this->cache->get(CastWithClassNameRequest::class);
    }

    public function testCastWithUnknownStringThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('neither a built-in type');
        $this->cache->get(UnknownCastTypeRequest::class);
    }

    public function testCasterWithoutInterfaceThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid #[Caster(');
        $this->cache->get(InvalidCasterClassRequest::class);
    }

    public function testCastAndCasterTogetherThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has both #[Cast] and #[Caster]');
        $this->cache->get(CastAndCasterRequest::class);
    }

    public function testGeneratorConflictsThrow(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Generator');
        $this->expectExceptionMessage('Validate');
        $this->cache->get(GeneratorWithValidateRequest::class);
    }

    public function testItemsCastConflictThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Items');
        $this->expectExceptionMessage('Cast');
        $this->cache->get(ItemsCastConflictRequest::class);
    }

    public function testItemsCasterConflictThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Items');
        $this->expectExceptionMessage('Caster');
        $this->cache->get(ItemsCasterConflictRequest::class);
    }

    public function testCasterWithNonExistentClassThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid #[Caster(');
        $this->cache->get(CasterWithNonExistentClassRequest::class);
    }

    // ===== cast resolution =====

    public function testUnionTypesWithCompatibleCast(): void
    {
        $metadata = $this->cache->get(UnionTypeRequest::class);

        $this->assertSame('float', $metadata->properties['price']->effectiveCastType);
    }

    public function testUnionTypesWithIncompatibleCastThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("cast type 'bool' which is incompatible with property type 'int|float'");
        $this->cache->get(InvalidUnionTypeRequest::class);
    }

    public function testCustomCasterClassStoredInCustomCasterClass(): void
    {
        $metadata = $this->cache->get(CustomCasterRequest::class);

        $this->assertNull($metadata->properties['value']->effectiveCastType);
        $this->assertSame(DummyCaster::class, $metadata->properties['value']->customCasterClass);
    }

    public function testTypeDrivenCastDefault(): void
    {
        $metadata = $this->cache->get(ValidRequest::class);
        $this->assertSame('string', $metadata->properties['name']->effectiveCastType);
    }

    // ===== processors =====

    public function testInvalidPreProcessorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("invalid preProcess 'nonExistentFunction'");
        $this->cache->get(InvalidPreProcessorRequest::class);
    }

    public function testInvalidPostProcessorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("invalid postProcess 'typoFunction'");
        $this->cache->get(InvalidPostProcessorRequest::class);
    }

    public function testProcessorClassWithoutInterfaceThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid preProcess');
        $this->cache->get(InvalidClassProcessorRequest::class);
    }

    public function testNonStaticProcessorMethodThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NON-static method');
        $this->cache->get(NonStaticProcessorMethodRequest::class);
    }

    public function testStaticProcessorMethodAccepted(): void
    {
        $metadata = $this->cache->get(StaticProcessorMethodRequest::class);
        $this->assertNotNull($metadata->properties['value']->preProcessor);
    }

    // ===== generator =====

    public function testGeneratorFieldMetadata(): void
    {
        $metadata = $this->cache->get(GeneratorFieldRequest::class);

        $this->assertSame(TestGenerator::class, $metadata->properties['id']->generator);
        $this->assertSame(['table' => 'users'], $metadata->properties['id']->generatorOptions);
        $this->assertNull($metadata->properties['name']->generator);
    }

    public function testInvalidGeneratorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid generator');
        $this->cache->get(InvalidGeneratorRequest::class);
    }

    public function testNonExistentGeneratorThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('class does not exist');
        $this->cache->get(NonExistentGeneratorRequest::class);
    }

    // ===== items =====

    public function testItemsMetadata(): void
    {
        $metadata = $this->cache->get(ValidItemsRequest::class);
        $this->assertSame(ItemRequest::class, $metadata->properties['items']->items);
    }

    public function testInvalidItemsClassThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('class does not exist');
        $this->cache->get(NonExistentItemsRequest::class);
    }

    public function testItemsNotRequestSubclassThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must extend Request');
        $this->cache->get(NotRequestItemsRequest::class);
    }

    public function testItemsRequiresArrayType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has 'items' but type is 'string'");
        $this->cache->get(ItemsNonArrayTypeRequest::class);
    }

    public function testBrokenItemsClassFailsTheParentBuild(): void
    {
        // Fail fast: the item class is validated with its parent, not when a
        // request first carries items.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('BrokenItemRequest::$qty');
        $this->cache->get(ParentOfBrokenItemRequest::class);
    }

    public function testSelfReferencingItemsClassBuilds(): void
    {
        $metadata = $this->cache->get(TreeNodeRequest::class);

        $this->assertSame(TreeNodeRequest::class, $metadata->properties['children']->items);
        $this->assertContains('children', $metadata->transformed, 'a class being built is assumed to need it');
    }

    // ===== datetime specs & cast compatibility =====

    // ===== nested DTOs & producibility =====

    public function testRequestTypedPropertyIsNested(): void
    {
        $metadata = $this->cache->get(ParentOfNestedRequest::class);

        $this->assertSame(ItemRequest::class, $metadata->properties['item']->nested);
        $this->assertSame(ItemRequest::class, $metadata->properties['maybe']->nested);
    }

    public function testChildrenJoinTheTransformPassOnlyWhenTheirClassNeedsIt(): void
    {
        // ItemRequest has no post-processor or caster anywhere: nothing to run.
        $this->assertSame([], $this->cache->get(ParentOfNestedRequest::class)->transformed);

        $this->assertSame(
            ['child', 'children', 'grandchild'],
            $this->cache->get(ParentOfHookedChildRequest::class)->transformed
        );
    }

    public function testRequestTypeProducedByCasterIsNotNested(): void
    {
        $this->assertNull($this->cache->get(CastedRequestTypeRequest::class)->properties['item']->nested);
    }

    public function testAbstractNestedTypeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('abstract');
        $this->cache->get(AbstractNestedParentRequest::class);
    }

    public function testBrokenNestedClassFailsTheParentBuild(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('BrokenItemRequest::$qty');
        $this->cache->get(BrokenNestedParentRequest::class);
    }

    public function testUnproducibleClassTypeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "type 'Solo\\RequestHandler\\Tests\\Unit\\Cache\\CacheMoney', which cannot be produced"
        );
        $this->cache->get(UnproducibleMoneyRequest::class);
    }

    public function testPureEnumWithoutCasterThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be produced from request input');
        $this->cache->get(PureEnumRequest::class);
    }

    #[DataProvider('typesInputCannotHoldProvider')]
    public function testTypesNoInputCanHoldNeedAProducer(string $requestClass, string $property): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("::\${$property} has type");
        $this->expectExceptionMessage('cannot be produced from request input');
        /** @var class-string $requestClass */
        $this->cache->get($requestClass);
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function typesInputCannotHoldProvider(): array
    {
        return [
            'object'           => [ObjectTypedRequest::class, 'meta'],
            'intersection'     => [IntersectionTypedRequest::class, 'items'],
            'union of classes' => [ClassUnionRequest::class, 'money'],
        ];
    }

    public function testUnionWithAnInputMemberGetsAGuardAndItsCasts(): void
    {
        $properties = $this->cache->get(UnionCastRequest::class)->properties;

        $this->assertNotNull($properties['amount']->typeGuard);
        $this->assertSame(['int', 'float'], $properties['amount']->unionCasts);
        $this->assertSame(['rule' => 'numeric'], $properties['amount']->castFailure);

        $this->assertSame(['int', 'string'], $properties['code']->unionCasts);
        $this->assertSame(['rule' => 'string'], $properties['code']->castFailure);

        // A member class may still be passed through context or a processor.
        $this->assertSame(['string'], $properties['when']->unionCasts);

        // #[Cast] decides on its own — no guard.
        $this->assertNull($properties['price']->typeGuard);
    }

    public function testStandaloneTrueTypeIsCastThroughBool(): void
    {
        $properties = $this->cache->get(LiteralTypesRequest::class)->properties;

        $this->assertNotNull($properties['accepted']->typeGuard);
        $this->assertSame(['bool'], $properties['accepted']->unionCasts);
        $this->assertSame(['rule' => 'boolean'], $properties['accepted']->castFailure);
        $this->assertSame(['string', 'bool'], $properties['nickname']->unionCasts);
    }

    public function testIterableHasItsOwnCast(): void
    {
        $property = $this->cache->get(UnionCastRequest::class)->properties['list'];

        $this->assertSame('iterable', $property->effectiveCastType);
        $this->assertNull($property->castFailure, 'reading like an array never fails');
    }

    public function testClassTypesWithAProducerAreAccepted(): void
    {
        $metadata = $this->cache->get(ProducibleObjectsRequest::class);

        $this->assertSame(['fromContext', 'casted', 'preProcessed'], array_keys($metadata->properties));
    }

    // ===== dates =====

    #[DataProvider('dateTypeProvider')]
    public function testDateClassComesFromTheDeclaredType(string $property, string $expectedClass): void
    {
        $metadata = $this->cache->get(DateTypesRequest::class);

        $this->assertSame($expectedClass, $metadata->properties[$property]->dateClass);
        $this->assertNull($metadata->properties[$property]->effectiveCastType);
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function dateTypeProvider(): array
    {
        return [
            'DateTimeImmutable'           => ['immutable', \DateTimeImmutable::class],
            '?DateTimeImmutable'          => ['maybe', \DateTimeImmutable::class],
            'DateTime'                    => ['mutable', \DateTime::class],
            'DateTimeInterface'           => ['either', \DateTimeImmutable::class],
            'mixed + #[DateFormat]'       => ['anything', \DateTimeImmutable::class],
            'DateTime + #[DateFormat]'    => ['formatted', \DateTime::class],
        ];
    }

    public function testDateFormatIsStoredWithItsFailureRule(): void
    {
        $property = $this->cache->get(DateTypesRequest::class)->properties['formatted'];

        $this->assertSame('Y-m-d H:i:s', $property->dateFormat);
        $this->assertSame(['rule' => 'date_format', 'params' => ['Y-m-d H:i:s']], $property->castFailure);
        $mutable = $this->cache->get(DateTypesRequest::class)->properties['mutable'];
        $this->assertSame(['rule' => 'date'], $mutable->castFailure);
    }

    public function testDatetimeCastSpecIsRejectedWithAHint(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('datetime casts were removed in 4.0');
        $this->expectExceptionMessage('#[DateFormat');
        $this->cache->get(DatetimeCastRequest::class);
    }

    public function testCastAliasesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("#[Cast('integer')] which is neither a built-in type");
        $this->cache->get(IntegerAliasCastRequest::class);
    }

    public function testDateFormatOnNonDateTypeThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has #[DateFormat] but type 'string'");
        $this->cache->get(DateFormatOnStringRequest::class);
    }

    public function testDateFormatWithCasterThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has both #[DateFormat] and #[Caster]');
        $this->cache->get(DateFormatWithCasterRequest::class);
    }

    public function testBackedEnumPropertyResolvesToEnumClass(): void
    {
        $property = $this->cache->get(EnumPropertyRequest::class)->properties['status'];

        $this->assertSame(CacheTestStatus::class, $property->enumClass);
        $this->assertNull($property->effectiveCastType);
    }

    public function testNonClassTypesDoNotProbeTheAutoloader(): void
    {
        // Review #10: enum detection must not ask the autoloader for 'mixed',
        // unions or intersections on every metadata build.
        $requested = [];
        $spy = static function (string $class) use (&$requested): void {
            $requested[] = $class;
        };
        spl_autoload_register($spy);

        try {
            $this->cache->get(NonClassTypesRequest::class);
        } finally {
            spl_autoload_unregister($spy);
        }

        $this->assertSame([], array_values(array_intersect($requested, ['mixed', 'int|string', 'Countable&Iterator'])));
    }

    public function testFloatCastOnIntPropertyThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("cast type 'float' which is incompatible with property type 'int'");
        $this->cache->get(FloatCastOnIntRequest::class);
    }

    public function testIntCastOnFloatAndAnyCastOnMixedAreAccepted(): void
    {
        $metadata = $this->cache->get(WideningCastRequest::class);

        $this->assertSame('int', $metadata->properties['amount']->effectiveCastType);
        $this->assertSame('array', $metadata->properties['payload']->effectiveCastType);
    }

    // ===== processor checks =====

    public function testNonPublicStaticProcessorMethodThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('NON-public method');
        $this->cache->get(ProtectedStaticProcessorRequest::class);
    }

    public function testProcessorNeedingTwoArgumentsThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'explode' that cannot be called with a single argument");
        $this->cache->get(TwoArgumentProcessorRequest::class);
    }

    public function testInternalFunctionTakingNoArgumentThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'time' that cannot be called with a single argument");
        $this->cache->get(NoArgumentProcessorRequest::class);
    }

    public function testStaticProcessorIsResolvedOnTheConcreteClass(): void
    {
        // The property lives on an abstract base, the hook on the subclass.
        $property = $this->cache->get(ConcreteHookRequest::class)->properties['value'];

        $this->assertSame(ProcessorKind::StaticMethod, $property->preProcessorKind);
    }

    public function testTypedCallableProcessorsGetAGuard(): void
    {
        $metadata = $this->cache->get(GuardedProcessorRequest::class);

        $this->assertNotNull($metadata->properties['typed']->preProcessorGuard);
        $this->assertNull($metadata->properties['mixed']->preProcessorGuard);
        $this->assertNull($metadata->properties['object']->preProcessorGuard);
    }
}

// ============================================================================
// Fixtures
// ============================================================================

final class ValidRequest extends Request
{
    #[Validate('required|string')]
    public string $name;
}

final class StaticPropertyRequest extends Request
{
    public static int $counter = 0;
    public string $name;
}

final class VisibilityRequest extends Request
{
    public string $publicField;
    protected string $protectedField = 'p';
    private string $privateField = 'pr';

    /** @return array<string, string> */
    public function reveal(): array
    {
        return ['protected' => $this->protectedField, 'private' => $this->privateField];
    }
}

final class GroupedRequest extends Request
{
    #[Validate('nullable|string')]
    #[Group('criteria')]
    public ?string $search = null;

    #[Validate('nullable|string')]
    #[Group('criteria')]
    public ?string $status = null;

    #[Validate('integer|min:1')]
    #[Cast('int')]
    public int $page = 1;
}

final class ExcludeFieldRequest extends Request
{
    #[Exclude]
    public string $excluded = 'default';

    #[Validate('required|string')]
    public string $normal;
}

final class RequiredTestRequest extends Request
{
    #[Validate('required|string')]
    public string $required;

    #[Validate('nullable|string')]
    public ?string $optional = null;

    #[Validate('required|nullable|string')]
    public ?string $requiredNullable;

    public string $noRules;
}

final class ConditionalPresenceRequest extends Request
{
    #[Validate('required|string')]
    public string $plainRequired;

    #[Validate('nullable|string|required_if:company_type,company')]
    public ?string $conditionallyRequired = null;

    #[Validate('nullable|string')]
    public ?string $plainOptional = null;
}

final class InRuleWithNullableLiteralRequest extends Request
{
    #[Validate('in:nullable,active')]
    public string $status;
}

final class RequiredInRuleRequest extends Request
{
    #[Validate('required|in:active,nullable')]
    public string $status;
}

final class UntypedPropertyRequest extends Request
{
    /** @var mixed */
    public $data;
}

final class FromRouteImplicitRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;
}

final class FromContextKeyRequest extends Request
{
    #[FromContext('authUserId')]
    public int $userId;
}

final class MultipleSourcesRequest extends Request
{
    #[FromRoute]
    #[FromContext('value')]
    public string $value;
}

final class InvalidNullableRequest extends Request
{
    #[Validate('nullable|email')]
    public string $email;
}

final class InvalidCastRequest extends Request
{
    #[Cast('string')]
    public int $id;
}

final class InvalidRequiredRequest extends Request
{
    #[Validate('required|string')]
    public string $name = 'default';
}

final class CastWithClassNameRequest extends Request
{
    #[Cast(DummyCaster::class)]
    public string $value;
}

final class UnknownCastTypeRequest extends Request
{
    #[Cast('unknown_type')]
    public string $value;
}

final class InvalidCasterClassRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not implement CasterInterface */
    #[Caster(ClassWithoutInterface::class)]
    public string $value;
}

final class CastAndCasterRequest extends Request
{
    #[Cast('int')]
    #[Caster(DummyCaster::class)]
    public int $value;
}

final class GeneratorWithValidateRequest extends Request
{
    #[Generator(TestGenerator::class)]
    #[Validate('required|string')]
    public string $id;
}

final class ItemsCastConflictRequest extends Request
{
    /** @var array<mixed> */
    #[Items(ItemRequest::class)]
    #[Cast('array')]
    public array $items = [];
}

final class ItemsCasterConflictRequest extends Request
{
    /** @var array<mixed> */
    #[Items(ItemRequest::class)]
    #[Caster(DummyCaster::class)]
    public array $items = [];
}

final class CasterWithNonExistentClassRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not exist */
    #[Caster('NonExistentCasterClass')]
    public string $value;
}

final class UnionTypeRequest extends Request
{
    #[Validate('numeric')]
    #[Cast('float')]
    public int|float $price;
}

final class InvalidUnionTypeRequest extends Request
{
    #[Cast('bool')]
    public int|float $count;
}

final class CustomCasterRequest extends Request
{
    #[Caster(DummyCaster::class)]
    public string $value;
}

final class DummyCaster implements \Solo\RequestHandler\Contracts\CasterInterface
{
    public function cast(mixed $value): mixed
    {
        return $value;
    }
}

final class InvalidPreProcessorRequest extends Request
{
    #[PreProcess('nonExistentFunction')]
    public string $name;
}

final class InvalidPostProcessorRequest extends Request
{
    #[PostProcess('typoFunction')]
    public string $value;
}

final class InvalidClassProcessorRequest extends Request
{
    #[PreProcess(ClassWithoutInterface::class)]
    public string $data;
}

final class ClassWithoutInterface
{
    public function process(mixed $value): mixed
    {
        return $value;
    }
}

final class NonStaticProcessorMethodRequest extends Request
{
    #[PreProcess('normalize')]
    public string $value;

    public function normalize(string $v): string  // intentionally non-static
    {
        return $v;
    }
}

final class StaticProcessorMethodRequest extends Request
{
    #[PreProcess('normalize')]
    public string $value;

    public static function normalize(string $v): string
    {
        return $v;
    }
}

final class GeneratorFieldRequest extends Request
{
    #[Generator(TestGenerator::class, options: ['table' => 'users'])]
    public string $id;

    #[Validate('required|string')]
    public string $name;
}

final class InvalidGeneratorRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not implement GeneratorInterface */
    #[Generator(NotAGenerator::class)]
    public string $id;
}

final class NonExistentGeneratorRequest extends Request
{
    /** @phpstan-ignore-next-line — intentional: class does not exist */
    #[Generator('NonExistentGenerator')]
    public string $id;
}

final class TestGenerator implements GeneratorInterface
{
    public function generate(array $options = []): string
    {
        return 'generated_value';
    }
}

final class NotAGenerator
{
    public function generate(): string
    {
        return 'not_a_generator';
    }
}

final class ItemRequest extends Request
{
    #[Validate('required|string')]
    public string $product;
}

final class ValidItemsRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var array<int, array<string, mixed>>|null */
    #[Validate('nullable|array')]
    #[Items(ItemRequest::class)]
    public ?array $items = null;
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

final class ItemsNonArrayTypeRequest extends Request
{
    #[Items(ItemRequest::class)]
    public string $items;
}

final class IgnoredFieldRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    #[Ignore]
    public ?object $currentUser = null;
}

final class IgnoreWithValidateRequest extends Request
{
    #[Ignore]
    #[Validate('required|string')]
    public string $foo;
}

final class IgnoreWithFromBodyRequest extends Request
{
    #[Ignore]
    #[FromBody('foo')]
    public string $foo;
}

final class FromBodyNestedRequest extends Request
{
    #[FromBody('customer.id')]
    public int $customerId;
}

final class FromBodyFlatKeyRequest extends Request
{
    #[FromBody('display_name')]
    public string $name;
}

final class FromBodyAndFromRouteRequest extends Request
{
    #[FromBody('foo')]
    #[FromRoute]
    public string $foo;
}

final class FromBodyAndFromContextRequest extends Request
{
    #[FromBody('foo')]
    #[FromContext('foo')]
    public string $foo;
}

final class ThreeSourcesRequest extends Request
{
    #[FromRoute]
    #[FromContext('foo')]
    #[FromBody('foo')]
    public string $foo;
}

final class AmbiguousTrimProcessorRequest extends Request
{
    #[PreProcess('trim')] // collides with global `trim` AND the static method below
    public string $value;

    public static function trim(string $v): string
    {
        return \trim($v, '/');
    }
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

abstract class AbstractGenerator implements GeneratorInterface
{
}

final class AbstractGeneratorRequest extends Request
{
    #[Generator(AbstractGenerator::class)]
    public string $id;
}

final class IntersectionTypeRequest extends Request
{
    #[FromContext('thing')]
    public \Countable&\Iterator $thing;
}

final class IgnoreWithGeneratorRequest extends Request
{
    #[Ignore]
    #[Generator(TestGenerator::class)]
    public string $id;
}

final class BrokenItemRequest extends Request
{
    #[Validate('nullable|integer')]
    public int $qty;
}

final class ParentOfBrokenItemRequest extends Request
{
    /** @var array<int, array<string, mixed>>|null */
    #[Items(BrokenItemRequest::class)]
    public ?array $lines = null;
}

final class TreeNodeRequest extends Request
{
    #[Validate('required|string')]
    public string $name;

    /** @var array<int, array<string, mixed>>|null */
    #[Validate('nullable|array')]
    #[Items(TreeNodeRequest::class)]
    public ?array $children = null;
}

enum CacheTestStatus: string
{
    case Draft = 'draft';
}

final class EnumPropertyRequest extends Request
{
    public CacheTestStatus $status;
}

final class FloatCastOnIntRequest extends Request
{
    #[Cast('float')]
    public int $qty;
}

final class WideningCastRequest extends Request
{
    #[Cast('int')]
    public float $amount;

    #[Cast('array')]
    public mixed $payload = null;
}

final class ProtectedStaticProcessorRequest extends Request
{
    #[PreProcess('normalize')]
    public string $value;

    protected static function normalize(string $v): string  // intentionally non-public
    {
        return $v;
    }
}

final class TwoArgumentProcessorRequest extends Request
{
    #[PreProcess('explode')]
    public string $value;
}

final class NoArgumentProcessorRequest extends Request
{
    #[PreProcess('time')]
    public string $value;
}

abstract class AbstractHookRequest extends Request
{
    #[PreProcess('normalizeValue')]
    public string $value;
}

final class ConcreteHookRequest extends AbstractHookRequest
{
    public static function normalizeValue(string $value): string
    {
        return strtolower($value);
    }
}

final class GuardTestProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): mixed
    {
        return $value;
    }
}

final class GuardedProcessorRequest extends Request
{
    #[PreProcess('strtolower')]
    public string $typed;

    #[PreProcess('intval')]
    public int $mixed;

    #[PreProcess(GuardTestProcessor::class)]
    public string $object;
}

final class NonClassTypesRequest extends Request
{
    public mixed $anything = null;
    public int|string $either = 0;

    #[FromContext('both')]
    public \Countable&\Iterator $both;
}

final class DateTypesRequest extends Request
{
    public \DateTimeImmutable $immutable;
    public ?\DateTimeImmutable $maybe = null;
    public \DateTime $mutable;
    public \DateTimeInterface $either;

    #[DateFormat('Y-m-d')]
    public mixed $anything = null;

    #[DateFormat('Y-m-d H:i:s')]
    public \DateTime $formatted;
}

final class DatetimeCastRequest extends Request
{
    #[Cast('datetime:immutable:Y-m-d')]
    public \DateTimeImmutable $date;
}

final class IntegerAliasCastRequest extends Request
{
    #[Cast('integer')]
    public int $count;
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

final class ParentOfNestedRequest extends Request
{
    public ItemRequest $item;
    public ?ItemRequest $maybe = null;
}

final class CastedRequestTypeRequest extends Request
{
    #[Caster(DummyCaster::class)]
    public ItemRequest $item;
}

final class AbstractNestedParentRequest extends Request
{
    public AbstractItemRequest $item;
}

final class BrokenNestedParentRequest extends Request
{
    public BrokenItemRequest $item;
}

final class CacheMoney
{
    public function __construct(public int $cents = 0)
    {
    }
}

final class UnproducibleMoneyRequest extends Request
{
    public CacheMoney $price;
}

enum PureSuit
{
    case Hearts;
}

final class PureEnumRequest extends Request
{
    public PureSuit $suit;
}

final class ProducibleObjectsRequest extends Request
{
    #[FromContext('money')]
    public CacheMoney $fromContext;

    #[Caster(DummyCaster::class)]
    public CacheMoney $casted;

    #[PreProcess(GuardTestProcessor::class)]
    public CacheMoney $preProcessed;
}

final class IgnoreWithEmptyToNullRequest extends Request
{
    #[Ignore]
    #[EmptyToNull]
    public ?string $note = null;
}

final class EmptyToNullPropertyRequest extends Request
{
    #[EmptyToNull]
    public ?string $note = null;

    public ?string $title = null;
}

#[EmptyToNull]
class EmptyToNullBaseRequest extends Request
{
    public ?string $note = null;

    #[Generator(TestGenerator::class)]
    public string $id;
}

final class EmptyToNullChildRequest extends EmptyToNullBaseRequest
{
    public ?string $extra = null;
}

final class GeneratorWithEmptyToNullRequest extends Request
{
    #[Generator(TestGenerator::class)]
    #[EmptyToNull]
    public string $id;
}

final class IgnoreWithNoTrimRequest extends Request
{
    #[Ignore]
    #[NoTrim]
    public ?string $note = null;
}

final class NoTrimPropertyRequest extends Request
{
    #[NoTrim]
    public string $snippet;

    public string $title;
}

#[NoTrim]
class NoTrimBaseRequest extends Request
{
    public string $body;
}

final class NoTrimChildRequest extends NoTrimBaseRequest
{
    public ?string $extra = null;
}

final class GeneratorWithNoTrimRequest extends Request
{
    #[Generator(TestGenerator::class)]
    #[NoTrim]
    public string $id;
}

final class ObjectTypedRequest extends Request
{
    public ?object $meta = null;
}

final class IntersectionTypedRequest extends Request
{
    /** @var \Countable&\ArrayAccess<int, mixed> */
    public \Countable&\ArrayAccess $items;
}

final class ClassUnionRequest extends Request
{
    public CacheMoney|\DateTimeImmutable $money;
}

final class UnionCastRequest extends Request
{
    public int|float $amount = 0;
    public int|string $code = 0;
    public \DateTimeImmutable|string|null $when = null;

    #[Cast('float')]
    public int|float $price = 0;

    /** @var iterable<mixed> */
    public iterable $list = [];
}

#[EmptyToNull]
#[NoTrim]
final class ClientInputClassRequest extends Request
{
    public ?string $note = null;

    #[FromContext('tenant')]
    public string $tenant;

    #[FromContext('explicit')]
    #[EmptyToNull]
    public ?string $explicit = null;
}

final class ContextDtoRequest extends Request
{
    #[FromContext('address')]
    public ValidRequest $address;
}

final class LiteralTypesRequest extends Request
{
    public true $accepted;
    public string|false $nickname = false;
}

final class HookedChildRequest extends Request
{
    #[PostProcess('strtoupper')]
    public ?string $name = null;
}

final class WrapsHookedChildRequest extends Request
{
    public ?HookedChildRequest $inner = null;
}

final class ParentOfHookedChildRequest extends Request
{
    public ?HookedChildRequest $child = null;

    /** @var list<HookedChildRequest> */
    #[Items(HookedChildRequest::class)]
    public array $children = [];

    // Two levels down: the wrapper itself has no hook, its child has.
    public ?WrapsHookedChildRequest $grandchild = null;

    public ?ItemRequest $plain = null;
}
