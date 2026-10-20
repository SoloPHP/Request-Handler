<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Exclude;
use Solo\RequestHandler\Attributes\Group;
use Solo\RequestHandler\Attributes\Ignore;
use Solo\RequestHandler\Cache\ReflectionCache;
use Solo\RequestHandler\Request;

final class RequestTest extends TestCase
{
    private TestRequest $dto;

    protected function setUp(): void
    {
        $this->dto = new TestRequest();
    }

    public function testGetReturnsPropertyValue(): void
    {
        $this->dto->name = 'John';
        $this->assertSame('John', $this->dto->get('name'));
    }

    public function testClearGroupCacheSpecificClass(): void
    {
        $registry = ReflectionCache::shared();
        $static = $registry->get(StaticGroupRequest::class);
        $multi = $registry->get(MultiGroupRequest::class);

        Request::clearCache(StaticGroupRequest::class);

        $this->assertNotSame($static, $registry->get(StaticGroupRequest::class));
        $this->assertSame($multi, $registry->get(MultiGroupRequest::class));
    }

    public function testToArrayDoesNotReplaceReferencedItems(): void
    {
        $item = new NestedItemRequest();
        $item->product = 'Widget';
        $item->quantity = 3;
        $original = $item;

        $parent = new ParentWithItemsRequest();
        $parent->items = ['first' => &$item, 7 => &$item];

        $this->assertSame([
            'items' => [
                'first' => ['product' => 'Widget', 'quantity' => 3],
                7 => ['product' => 'Widget', 'quantity' => 3],
            ],
        ], $parent->toArray());
        $this->assertSame($original, $item);
        $this->assertSame(['first' => $original, 7 => $original], $parent->items);
    }

    public function testToArrayCopiesArraysAndSkipsWhatItDoesNotExport(): void
    {
        $first = 'a';
        $dto = new ExportedRequest();
        $dto->tags = [&$first, 'b'];
        $dto->secret = 'hidden';

        $exported = $dto->toArray();
        $first = 'changed';

        // A copy of the array: a reference in it does not reach the export.
        // #[Exclude] and uninitialised properties are left out.
        $this->assertSame(['tags' => ['a', 'b']], $exported);
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
}

final class StaticGroupRequest extends Request
{
    public static int $counter = 0;

    #[Group('criteria')]
    public ?string $search = null;
}

final class MultiGroupRequest extends Request
{
    #[Group('criteria')]
    public ?string $search = null;

    #[Group('criteria')]
    public ?string $status;
}

final class NestedItemRequest extends Request
{
    public string $product;
    public int $quantity;
}

final class ParentWithItemsRequest extends Request
{
    /** @var NestedItemRequest[] */
    public array $items;
}

final class ExportedRequest extends Request
{
    /** @var list<string> */
    public array $tags;

    #[Exclude]
    public string $secret;

    public string $unset;
}

final class IgnoredPropertyRequest extends Request
{
    public string $name;

    #[Ignore]
    public string $computed = 'default';
}
