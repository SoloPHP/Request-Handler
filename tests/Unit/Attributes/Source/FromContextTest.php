<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Source\FromContext;

final class FromContextTest extends TestCase
{
    public function testAcceptsKey(): void
    {
        $attr = new FromContext('authenticatedUserId');
        $this->assertSame('authenticatedUserId', $attr->key);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[FromContext] key must be a non-empty string');
        new FromContext('');
    }
}
