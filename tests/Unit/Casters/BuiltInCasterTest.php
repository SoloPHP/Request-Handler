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
        foreach (['int', 'float', 'bool', 'string', 'array'] as $type) {
            $this->assertTrue(BuiltInCaster::isBuiltIn($type), $type);
        }
        // 4.0: no aliases, no datetime specs.
        foreach (['integer', 'double', 'boolean', 'datetime', 'MyClass'] as $type) {
            $this->assertFalse(BuiltInCaster::isBuiltIn($type), $type);
        }
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

    public function testCastNull(): void
    {
        $this->assertNull($this->caster->cast('int', null));
        $this->assertNull($this->caster->cast('string', null));
        $this->assertNull($this->caster->cast('array', null));
    }

    public function testCastEmptyString(): void
    {
        $this->assertNull($this->caster->cast('int', ''));
        $this->assertNull($this->caster->cast('float', ''));
        $this->assertSame('', $this->caster->cast('string', ''));
        $this->assertFalse($this->caster->cast('bool', ''));
        $this->assertSame([], $this->caster->cast('array', ''));
    }

    public function testCastFloatNonNumeric(): void
    {
        $this->assertSame(0.0, $this->caster->cast('float', 'abc'));
    }

    public function testCastBoolNonStandard(): void
    {
        $this->assertTrue($this->caster->cast('bool', 'anything'));
        $this->assertTrue($this->caster->cast('bool', 100));
        $this->assertFalse($this->caster->cast('bool', 0));
    }

    public function testCastStringFromObject(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'test-object';
            }
        };
        $this->assertSame('test-object', $this->caster->cast('string', $obj));
    }

    public function testCastStringFromNonStringable(): void
    {
        $this->assertSame('', $this->caster->cast('string', new \stdClass()));
    }

    public function testCastArrayFromObject(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        $this->assertSame(['key' => 'value'], $this->caster->cast('array', $obj));
    }

    public function testCastUnknownTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cast type: unknown');
        $this->caster->cast('unknown', 'value');
    }

    public function testRejectsArray(): void
    {
        foreach (['int', 'float', 'bool'] as $type) {
            $this->assertTrue(BuiltInCaster::rejectsArray($type), $type);
        }
        foreach (['string', 'array'] as $type) {
            $this->assertFalse(BuiltInCaster::rejectsArray($type), $type);
        }
    }

    // ===== dates =====

    public function testCastDateParsesStringsInTheRequestedClass(): void
    {
        $mutable = $this->caster->castDate(DateTime::class, null, '2023-01-01');
        $this->assertInstanceOf(DateTime::class, $mutable);
        $this->assertSame('2023-01-01', $mutable->format('Y-m-d'));

        $immutable = $this->caster->castDate(DateTimeImmutable::class, null, '2023-01-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $immutable);
    }

    public function testCastDateReadsTimestamps(): void
    {
        $fromInt = $this->caster->castDate(DateTimeImmutable::class, null, 1672531200);
        $this->assertInstanceOf(DateTimeImmutable::class, $fromInt);
        $this->assertSame(1672531200, $fromInt->getTimestamp());

        // The DateTime parser rejects a bare digit string; query strings deliver one.
        $fromString = $this->caster->castDate(DateTime::class, null, '1700000000');
        $this->assertInstanceOf(DateTime::class, $fromString);
        $this->assertSame(1700000000, $fromString->getTimestamp());
    }

    public function testCastDateFormatContainingColons(): void
    {
        $casted = $this->caster->castDate(DateTimeImmutable::class, 'Y-m-d H:i:s', '2024-01-15 10:30:45');

        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-15 10:30:45', $casted->format('Y-m-d H:i:s'));
    }

    public function testDateOnlyFormatYieldsMidnight(): void
    {
        $casted = $this->caster->castDate(DateTime::class, 'Y-m-d', '2024-01-15');
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertSame('2024-01-15 00:00:00', $casted->format('Y-m-d H:i:s'));

        $casted = $this->caster->castDate(DateTimeImmutable::class, 'd.m.Y', '15.01.2024');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-15 00:00:00', $casted->format('Y-m-d H:i:s'));
    }

    public function testPartialDateFormatsResetOnlyTheTime(): void
    {
        // Fields the format does not parse keep PHP's semantics (current year
        // for `m-d`) — only the time of day is reset.
        $currentYear = date('Y');
        $casted = $this->caster->castDate(DateTimeImmutable::class, 'm-d', '05-17');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame($currentYear . '-05-17 00:00:00', $casted->format('Y-m-d H:i:s'));

        $casted = $this->caster->castDate(DateTimeImmutable::class, '!Y-m', '2024-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-01 00:00:00', $casted->format('Y-m-d H:i:s'));
    }

    public function testCastDateConvertsBetweenDateClasses(): void
    {
        $mutable = new DateTime('2024-01-15 10:30:00');
        $immutable = $this->caster->castDate(DateTimeImmutable::class, null, $mutable);
        $this->assertInstanceOf(DateTimeImmutable::class, $immutable);
        $this->assertSame($mutable->getTimestamp(), $immutable->getTimestamp());

        $back = $this->caster->castDate(DateTime::class, null, $immutable);
        $this->assertInstanceOf(DateTime::class, $back);

        $same = new DateTimeImmutable('2024-01-15');
        $this->assertSame($same, $this->caster->castDate(DateTimeImmutable::class, null, $same));
    }

    public function testCastDateReturnsNullForUnreadableInput(): void
    {
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d', 'invalid'));
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, 'not-a-date-at-all'));
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, ['2024-01-01']));
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, ''));
    }

    // ===== enums =====

    public function testCastStringBackedEnum(): void
    {
        $this->assertSame(CasterTestStatus::Draft, $this->caster->castEnum(CasterTestStatus::class, 'draft'));
        $this->assertNull($this->caster->castEnum(CasterTestStatus::class, 'archived'));
        $this->assertNull($this->caster->castEnum(CasterTestStatus::class, ['draft']));
    }

    public function testCastIntBackedEnumAcceptsNumericStrings(): void
    {
        $this->assertSame(CasterTestLevel::High, $this->caster->castEnum(CasterTestLevel::class, '2'));
        $this->assertSame(CasterTestLevel::High, $this->caster->castEnum(CasterTestLevel::class, 2));
        $this->assertNull($this->caster->castEnum(CasterTestLevel::class, '2.5'));
        $this->assertNull($this->caster->castEnum(CasterTestLevel::class, true));
    }

    public function testCastEnumPassesInstanceThrough(): void
    {
        $this->assertSame(CasterTestLevel::Low, $this->caster->castEnum(CasterTestLevel::class, CasterTestLevel::Low));
    }
}

enum CasterTestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum CasterTestLevel: int
{
    case Low = 1;
    case High = 2;
}
