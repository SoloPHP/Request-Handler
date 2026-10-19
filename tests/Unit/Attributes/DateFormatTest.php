<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\DateFormat;

final class DateFormatTest extends TestCase
{
    public function testStoresFormat(): void
    {
        $this->assertSame('Y-m-d H:i:s', (new DateFormat('Y-m-d H:i:s'))->format);
    }

    public function testRejectsEmptyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DateFormat('');
    }
}
