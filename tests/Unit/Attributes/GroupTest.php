<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Group;

final class GroupTest extends TestCase
{
    public function testAcceptsNameOnly(): void
    {
        $attr = new Group('criteria');
        $this->assertSame('criteria', $attr->name);
        $this->assertNull($attr->mapTo);
    }

    public function testAcceptsNameAndMapTo(): void
    {
        $attr = new Group('criteria', mapTo: 'positions.id');
        $this->assertSame('criteria', $attr->name);
        $this->assertSame('positions.id', $attr->mapTo);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Group] name must be a non-empty string');
        new Group('');
    }

    public function testRejectsEmptyMapTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Group] mapTo must be a non-empty string or null');
        new Group('criteria', mapTo: '');
    }
}
