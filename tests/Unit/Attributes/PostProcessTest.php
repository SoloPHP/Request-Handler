<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Attributes\PostProcess;

final class PostProcessTest extends TestCase
{
    public function testAcceptsHandlerAndConfig(): void
    {
        $attr = new PostProcess('SlugProcessor', config: ['separator' => '-']);
        $this->assertSame('SlugProcessor', $attr->handler);
        $this->assertSame(['separator' => '-'], $attr->config);
    }

    public function testConfigDefaultsToEmptyArray(): void
    {
        $attr = new PostProcess('trim');
        $this->assertSame([], $attr->config);
    }

    public function testRejectsEmptyHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[PostProcess] handler must be a non-empty string');
        new PostProcess('');
    }
}
