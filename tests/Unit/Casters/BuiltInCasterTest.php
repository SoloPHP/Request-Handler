<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Casters;

use PHPUnit\Framework\TestCase;
use Solo\RequestHandler\Casters\BuiltInCaster;
use DateTime;
use DateTimeImmutable;

final class BuiltInCasterTest extends TestCase
{
    private BuiltInCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new BuiltInCaster();
    }

    public function testIsBuiltIn(): void
    {
        $this->assertTrue($this->caster->isBuiltIn('int'));
        $this->assertTrue($this->caster->isBuiltIn('integer'));
        $this->assertTrue($this->caster->isBuiltIn('string'));
        $this->assertTrue($this->caster->isBuiltIn('datetime'));
        $this->assertTrue($this->caster->isBuiltIn('datetime:Y-m-d'));
        $this->assertFalse($this->caster->isBuiltIn('MyClass'));
    }

    public function testCastInt(): void
    {
        $this->assertSame(123, $this->caster->cast('int', '123'));
        $this->assertSame(1, $this->caster->cast('int', true));
        $this->assertSame(0, $this->caster->cast('int', false));
        $this->assertSame(0, $this->caster->cast('int', 'abc'));
    }

    public function testCastFloat(): void
    {
        $this->assertSame(12.34, $this->caster->cast('float', '12.34'));
        $this->assertSame(1.0, $this->caster->cast('float', true));
        $this->assertSame(0.0, $this->caster->cast('float', false));
    }

    public function testCastBool(): void
    {
        $this->assertTrue($this->caster->cast('bool', 'true'));
        $this->assertTrue($this->caster->cast('bool', '1'));
        $this->assertTrue($this->caster->cast('bool', 'yes'));
        $this->assertTrue($this->caster->cast('bool', 'on'));
        $this->assertFalse($this->caster->cast('bool', 'false'));
        $this->assertFalse($this->caster->cast('bool', '0'));
        $this->assertFalse($this->caster->cast('bool', 'no'));
        $this->assertFalse($this->caster->cast('bool', 'off'));
        $this->assertTrue($this->caster->cast('bool', true));
        $this->assertFalse($this->caster->cast('bool', false));
    }

    public function testCastString(): void
    {
        $this->assertSame('123', $this->caster->cast('string', 123));
        $this->assertSame('{"a":1}', $this->caster->cast('string', ['a' => 1]));
    }

    public function testCastArray(): void
    {
        $this->assertSame(['a' => 1], $this->caster->cast('array', ['a' => 1]));
        $this->assertSame(['a', 'b'], $this->caster->cast('array', '["a","b"]'));
        $this->assertSame(['a', 'b'], $this->caster->cast('array', 'a,b'));
        $this->assertSame(['val'], $this->caster->cast('array', 'val'));
    }

    public function testCastDateTime(): void
    {
        $date = new DateTime('2023-01-01');
        $this->assertEquals($date, $this->caster->cast('datetime', $date));

        $casted = $this->caster->cast('datetime', '2023-01-01');
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertEquals('2023-01-01', $casted->format('Y-m-d'));

        $casted = $this->caster->cast('datetime:Y-m-d', '2023-01-01');
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertEquals('2023-01-01', $casted->format('Y-m-d'));

        $timestamp = 1672531200; // 2023-01-01 00:00:00 UTC
        $casted = $this->caster->cast('datetime', $timestamp);
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertEquals($timestamp, $casted->getTimestamp());
    }

    public function testCastDateTimeImmutable(): void
    {
        $casted = $this->caster->cast('datetime:immutable', '2023-01-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertEquals('2023-01-01', $casted->format('Y-m-d'));
    }
}
