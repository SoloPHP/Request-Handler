<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\DateFormat;

final class DateFormatTest extends TestCase
{
    public function testRejectsEmptyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DateFormat('');
    }
}
