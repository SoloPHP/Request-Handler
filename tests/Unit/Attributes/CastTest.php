<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Cast;

final class CastTest extends TestCase
{
    public function testAcceptsNonEmptyType(): void
    {
        $attr = new Cast('datetime:immutable:Y-m-d');
        $this->assertSame('datetime:immutable:Y-m-d', $attr->type);
    }

    public function testRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Cast] type must be a non-empty string');
        new Cast('');
    }
}
