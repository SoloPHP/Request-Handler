<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Source\FromRoute;

final class FromRouteTest extends TestCase
{
    public function testDefaultsToNullKey(): void
    {
        $attr = new FromRoute();
        $this->assertNull($attr->key);
    }

    public function testAcceptsExplicitKey(): void
    {
        $attr = new FromRoute('product_id');
        $this->assertSame('product_id', $attr->key);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[FromRoute] key must be a non-empty string or null');
        new FromRoute('');
    }
}
