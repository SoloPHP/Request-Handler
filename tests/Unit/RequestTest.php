<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit;

use Error;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Field;
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

        $expected = [
            'name' => 'John',
            'age' => 30,
            'email' => 'john@example.com',
        ];

        $this->assertEquals($expected, $this->dto->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoPropertiesInitialized(): void
    {
        $this->assertEquals([], $this->dto->toArray());
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

        $this->assertEquals('John', $this->dto->get('name'));
    }

    public function testGetReturnsDefaultForUninitializedProperty(): void
    {
        $this->assertEquals('default', $this->dto->get('name', 'default'));
    }

    public function testGetReturnsDefaultForNonExistingProperty(): void
    {
        $this->assertEquals('default', $this->dto->get('nonexistent', 'default'));
    }

    public function testGetReturnsNullByDefaultForUninitializedProperty(): void
    {
        $this->assertNull($this->dto->get('name'));
    }

    public function testAccessingUninitializedPropertyThrowsError(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');

        $value = $this->dto->name;
    }

    public function testGroupIgnoresStaticProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';

        $result = $dto->group('criteria');

        $this->assertEquals(['search' => 'test'], $result);
        $this->assertArrayNotHasKey('counter', $result);
    }

    public function testGroupIgnoresPropertiesWithoutFieldAttribute(): void
    {
        $dto = new MixedGroupRequest();
        $dto->search = 'test';
        $dto->noAttribute = 'value';

        $result = $dto->group('criteria');

        $this->assertEquals(['search' => 'test'], $result);
        $this->assertArrayNotHasKey('noAttribute', $result);
    }

    public function testToArrayIgnoresStaticProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';
        StaticGroupRequest::$counter = 100;

        $result = $dto->toArray();

        $this->assertEquals(['search' => 'test'], $result);
        $this->assertArrayNotHasKey('counter', $result);
    }

    public function testGroupReturnsEmptyArrayForNonExistingGroup(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';

        $result = $dto->group('nonexistent');

        $this->assertEquals([], $result);
    }

    public function testGroupCachesProperties(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test1';

        // First call - builds cache
        $result1 = $dto->group('criteria');
        $this->assertEquals(['search' => 'test1'], $result1);

        // Modify property
        $dto->search = 'test2';

        // Second call - uses cache (same properties list)
        $result2 = $dto->group('criteria');
        $this->assertEquals(['search' => 'test2'], $result2);
    }

    public function testGroupOnlyReturnsInitializedProperties(): void
    {
        $dto = new MultiGroupRequest();
        $dto->search = 'test';
        // status is not initialized

        $result = $dto->group('criteria');

        $this->assertEquals(['search' => 'test'], $result);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testClearGroupCacheAll(): void
    {
        $dto = new StaticGroupRequest();
        $dto->search = 'test';

        // Build cache
        $dto->group('criteria');

        // Verify cache exists
        $reflection = new \ReflectionClass('Solo\\RequestHandler\\Request');
        $cacheProperty = $reflection->getProperty('groupCache');
        $cache = $cacheProperty->getValue();
        $this->assertNotEmpty($cache);

        // Clear all cache
        Request::clearGroupCache();

        // Verify cache is empty
        $cache = $cacheProperty->getValue();
        $this->assertEmpty($cache);
    }

    public function testClearGroupCacheSpecificClass(): void
    {
        $dto1 = new StaticGroupRequest();
        $dto1->search = 'test1';

        $dto2 = new MultiGroupRequest();
        $dto2->search = 'test2';

        // Build cache for both
        $dto1->group('criteria');
        $dto2->group('criteria');

        // Verify cache exists for both
        $reflection = new \ReflectionClass('Solo\\RequestHandler\\Request');
        $cacheProperty = $reflection->getProperty('groupCache');
        $cache = $cacheProperty->getValue();
        $this->assertArrayHasKey(StaticGroupRequest::class, $cache);
        $this->assertArrayHasKey(MultiGroupRequest::class, $cache);

        // Clear cache only for StaticGroupRequest
        Request::clearGroupCache(StaticGroupRequest::class);

        // Verify only StaticGroupRequest cache is cleared
        $cache = $cacheProperty->getValue();
        $this->assertArrayNotHasKey(StaticGroupRequest::class, $cache);
        $this->assertArrayHasKey(MultiGroupRequest::class, $cache);
    }

    public function testMessagesReturnsEmptyArrayByDefault(): void
    {
        $dto = new TestRequest();

        $this->assertEquals([], $dto->getMessages());
    }

    public function testCustomMessagesAreReturned(): void
    {
        $dto = new CustomMessagesRequest();

        $expected = [
            'name.required' => 'Name is required',
            'email.email' => 'Invalid email format',
        ];

        $this->assertEquals($expected, $dto->getMessages());
    }
}

final class TestRequest extends Request
{
    public string $name;
    public int $age;
    public string $email;
}

final class StaticGroupRequest extends Request
{
    public static int $counter = 0;

    #[Field(group: 'criteria')]
    public ?string $search = null;
}

final class MixedGroupRequest extends Request
{
    #[Field(group: 'criteria')]
    public ?string $search = null;

    public ?string $noAttribute = null;
}

final class MultiGroupRequest extends Request
{
    #[Field(group: 'criteria')]
    public ?string $search = null;

    #[Field(group: 'criteria')]
    public ?string $status;
}

final class CustomMessagesRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;

    #[Field(rules: 'required|email')]
    public string $email;

    protected function messages(): array
    {
        return [
            'name.required' => 'Name is required',
            'email.email' => 'Invalid email format',
        ];
    }
}
