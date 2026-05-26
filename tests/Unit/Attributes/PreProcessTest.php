<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\PreProcess;

final class PreProcessTest extends TestCase
{
    public function testAcceptsNonEmptyHandler(): void
    {
        $attr = new PreProcess('trim');
        $this->assertSame('trim', $attr->handler);
    }

    public function testRejectsEmptyHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[PreProcess] handler must be a non-empty string');
        new PreProcess('');
    }
}
