<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\Validate;

final class ValidateTest extends TestCase
{
    public function testAcceptsNonEmptyRules(): void
    {
        $attr = new Validate('required|string');
        $this->assertSame('required|string', $attr->rules);
    }

    public function testRejectsEmptyRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Validate] rules must be a non-empty string');
        new Validate('');
    }
}
