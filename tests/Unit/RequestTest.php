<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Request;

final class RequestTest extends TestCase
{
    private TestRequest $dto;

    protected function setUp(): void
    {
        $this->dto = new TestRequest();
    }

    public function testToArrayReturnsAllInitializedProperties(): void
    {
        $this->dto->name = 'John';
        $this->dto->age = 30;
        $this->dto->email = 'john@example.com';

        $this->assertSame(
            ['name' => 'John', 'age' => 30, 'email' => 'john@example.com'],
            $this->dto->toArray()
        );
    }

    public function testToArrayReturnsEmptyArrayWhenNoPropertiesInitialized(): void
    {
        $this->assertSame([], $this->dto->toArray());
    }

    public function testHasReturnsTrueForInitializedProperty(): void
    {
        $this->dto->name = 'John';
        $this->assertTrue($this->dto->has('name'));
    }

    public function testHasReturnsFalseForUninitializedProperty(): void
    {
        $this->assertFalse($this->dto->has('name'));
    }

    public function testHasReturnsFalseForNonExistingProperty(): void
    {
        $this->assertFalse($this->dto->has('nonexistent'));
    }

    public function testGetReturnsPropertyValue(): void
    {
        $this->dto->name = 'John';
        $this->assertSame('John', $this->dto->get('name'));
    }

    public function testGetReturnsDefaultForUninitializedProperty(): void
    {
        $this->assertSame('default', $this->dto->get('name', 'default'));
    }

    public function testGetReturnsDefaultForNonExistingProperty(): void
    {
        $this->assertSame('default', $this->dto->get('nonexistent', 'default'));
    }

    public function testGroupIgnoresStaticProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';

        $result = $dto->group('criteria');

        $this->assertSame(['search' => 'test'], $result);
        $this->assertArrayNotHasKey('counter', $result);
    }

    public function testGroupIgnoresPropertiesWithoutGroupAttribute(): void
    {
        $dto = new MixedGroupRequest();
        $dto->search = 'test';
        $dto->noAttribute = 'value';

        $this->assertSame(['search' => 'test'], $dto->group('criteria'));
    }

    public function testGroupSkipsNullScalarMembers(): void
    {
        $dto = new MixedGroupRequest();
        // search left null; noAttribute outside the group anyway.
        $this->assertSame([], $dto->group('criteria'));
    }

    public function testToArrayIgnoresStaticProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';
        StaticGroupRequest::$counter = 100;

        $this->assertSame(['search' => 'test'], $dto->toArray());
    }

    public function testGroupReturnsEmptyArrayForNonExistingGroup(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';

        $this->assertSame([], $dto->group('nonexistent'));
    }

    public function testGroupCachesProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test1';
        $this->assertSame(['search' => 'test1'], $dto->group('criteria'));

        $dto->search = 'test2';
        $this->assertSame(['search' => 'test2'], $dto->group('criteria'));
    }

    public function testGroupOnlyReturnsInitializedProperties(): void
    {
        $dto = new MultiGroupRequest();
        $dto->search = 'test';

        $this->assertSame(['search' => 'test'], $dto->group('criteria'));
    }

    public function testClearGroupCacheAll(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';
        $dto->group('criteria');

        $reflection = new \ReflectionClass(Request::class);
        $cacheProperty = $reflection->getProperty('groupCache');
        $this->assertNotEmpty($cacheProperty->getValue());

        Request::clearCache();

        $this->assertEmpty($cacheProperty->getValue());
    }

    public function testClearGroupCacheSpecificClass(): void
    {
        $dto1 = new StaticGroupRequest();
        $dto1->search = 'test1';
        $dto1->group('criteria');

        $dto2 = new MultiGroupRequest();
        $dto2->search = 'test2';
        $dto2->group('criteria');

        $reflection = new \ReflectionClass(Request::class);
        $cacheProperty = $reflection->getProperty('groupCache');
        $cache = $cacheProperty->getValue();
        $this->assertArrayHasKey(StaticGroupRequest::class, $cache);
        $this->assertArrayHasKey(MultiGroupRequest::class, $cache);

        Request::clearCache(StaticGroupRequest::class);

        $cache = $cacheProperty->getValue();
        $this->assertArrayNotHasKey(StaticGroupRequest::class, $cache);
        $this->assertArrayHasKey(MultiGroupRequest::class, $cache);
    }

    public function testGroupFlattensAssociativeArrays(): void
    {
        $dto = new ArrayGroupRequest();
        $dto->search = ['name' => ['LIKE', '%Admin%']];
        $dto->deleted = ['deleted_at' => ['!=', null]];

        $this->assertSame(
            ['name' => ['LIKE', '%Admin%'], 'deleted_at' => ['!=', null]],
            $dto->group('criteria')
        );
    }

    public function testGroupMixesArraysAndScalars(): void
    {
        $dto = new MixedTypeGroupRequest();
        $dto->filters = ['status' => 'active'];
        $dto->limit = 10;

        $this->assertSame(['status' => 'active', 'limit' => 10], $dto->group('criteria'));
    }

    public function testGroupThrowsExceptionOnDuplicateKeyFromArrays(): void
    {
        $dto = new DuplicateKeyRequest();
        $dto->first = ['name' => 'value1'];
        $dto->second = ['name' => 'value2'];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate key 'name' in group 'criteria' from property 'second'");

        $dto->group('criteria');
    }

    public function testGroupThrowsExceptionOnDuplicateKeyFromScalar(): void
    {
        $dto = new ScalarDuplicateRequest();
        $dto->filters = ['limit' => 100];
        $dto->limit = 10;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate key 'limit' in group 'criteria' from property 'limit'");

        $dto->group('criteria');
    }

    public function testToArrayExcludesFieldsWithExcludeAttribute(): void
    {
        $dto = new ExcludedFieldToArrayRequest();
        $dto->name = 'John';

        $this->assertSame(['name' => 'John'], $dto->toArray());
    }

    public function testToArrayConvertsNestedRequestObjects(): void
    {
        $item = new NestedItemRequest();
        $item->product = 'Widget';
        $item->quantity = 3;

        $parent = new ParentWithItemsRequest();
        $parent->name = 'Order #1';
        $parent->items = [$item];

        $this->assertSame(
            ['name' => 'Order #1', 'items' => [['product' => 'Widget', 'quantity' => 3]]],
            $parent->toArray()
        );
    }

    public function testToArrayConvertsDirectNestedRequest(): void
    {
        $child = new NestedItemRequest();
        $child->product = 'Gadget';
        $child->quantity = 1;

        $parent = new ParentWithChildRequest();
        $parent->name = 'Test';
        $parent->child = $child;

        $this->assertSame(
            ['name' => 'Test', 'child' => ['product' => 'Gadget', 'quantity' => 1]],
            $parent->toArray()
        );
    }

    public function testGroupUsesMapToAsOutputKey(): void
    {
        $dto = new MapToGroupRequest();
        $dto->position_id = 5;
        $dto->search = 'test';

        $this->assertSame(['positions.id' => 5, 'search' => 'test'], $dto->group('criteria'));
    }

    public function testGroupSkipsEmptyArrays(): void
    {
        $dto = new ArrayGroupRequest();
        $dto->search = [];
        $dto->deleted = ['deleted_at' => ['!=', null]];

        $this->assertSame(['deleted_at' => ['!=', null]], $dto->group('criteria'));
    }

    public function testGroupStoresSequentialArrayUnderPropertyName(): void
    {
        $dto = new SequentialArrayGroupRequest();
        $dto->statuses = ['pending', 'partially_paid'];
        $dto->search = ['name' => ['LIKE', '%test%']];

        $this->assertSame(
            ['statuses' => ['pending', 'partially_paid'], 'name' => ['LIKE', '%test%']],
            $dto->group('criteria')
        );
    }

    public function testGroupStoresSequentialArrayWithMapTo(): void
    {
        $dto = new SequentialArrayMapToRequest();
        $dto->statuses = ['pending', 'active'];

        $this->assertSame(['order_status' => ['pending', 'active']], $dto->group('criteria'));
    }

    public function testProtectedPropertiesAreNotInToArray(): void
    {
        $dto = new InternalStateRequest();
        $dto->name = 'X';
        // protected $internal can't be set from outside (and handler doesn't touch it either).

        $this->assertSame(['name' => 'X'], $dto->toArray());
    }

    public function testIgnoredPublicPropertyIsHiddenFromAllAccessors(): void
    {
        $dto = new IgnoredPropertyRequest();
        $dto->name = 'X';
        $dto->computed = 'set-by-controller';

        $this->assertSame(['name' => 'X'], $dto->toArray());
        $this->assertFalse($dto->has('computed'));
        $this->assertSame('fallback', $dto->get('computed', 'fallback'));
        $this->assertSame([], $dto->group('criteria'));
    }
}

// ============================================================================
// Fixtures
// ============================================================================

final class TestRequest extends Request
{
    public string $name;
    public int $age;
    public string $email;
}

final class StaticGroupRequest extends Request
{
    public static int $counter = 0;

    #[Group('criteria')]
    public ?string $search = null;
}

final class MixedGroupRequest extends Request
{
    #[Group('criteria')]
    public ?string $search = null;

    public ?string $noAttribute = null;
}

final class MultiGroupRequest extends Request
{
    #[Group('criteria')]
    public ?string $search = null;

    #[Group('criteria')]
    public ?string $status;
}

final class ArrayGroupRequest extends Request
{
    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $search;

    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $deleted;
}

final class MixedTypeGroupRequest extends Request
{
    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $filters;

    #[Group('criteria')]
    public int $limit;
}

final class DuplicateKeyRequest extends Request
{
    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $first;

    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $second;
}

final class ScalarDuplicateRequest extends Request
{
    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $filters;

    #[Group('criteria')]
    public int $limit;
}

final class ExcludedFieldToArrayRequest extends Request
{
    public string $name;

    #[Exclude]
    public string $internal = 'secret';
}

final class MapToGroupRequest extends Request
{
    #[Group('criteria', mapTo: 'positions.id')]
    public int $position_id;

    #[Group('criteria')]
    public ?string $search = null;
}

final class SequentialArrayGroupRequest extends Request
{
    /** @var array<string> */
    #[Group('criteria')]
    public array $statuses;

    /** @var array<string, mixed> */
    #[Group('criteria')]
    public array $search;
}

final class SequentialArrayMapToRequest extends Request
{
    /** @var array<string> */
    #[Group('criteria', mapTo: 'order_status')]
    public array $statuses;
}

final class NestedItemRequest extends Request
{
    public string $product;
    public int $quantity;
}

final class ParentWithItemsRequest extends Request
{
    public string $name;
    /** @var NestedItemRequest[] */
    public array $items;
}

final class ParentWithChildRequest extends Request
{
    public string $name;
    public NestedItemRequest $child;
}

final class InternalStateRequest extends Request
{
    public string $name;
    protected string $internal = 'private-default';
}

final class IgnoredPropertyRequest extends Request
{
    public string $name;

    #[Ignore]
    public string $computed = 'default';
}
