<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Tests\Unit\Casters;

use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertNull($this->caster->cast('datetime', ''));
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
        $obj = new \stdClass();
        $this->assertSame('', $this->caster->cast('string', $obj));
    }

    public function testCastArrayFromObject(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        $result = $this->caster->cast('array', $obj);
        $this->assertSame(['key' => 'value'], $result);
    }

    public function testCastDateTimeInvalidFormat(): void
    {
        $result = $this->caster->cast('datetime:Y-m-d', 'invalid');
        $this->assertNull($result);
    }

    public function testCastDateTimeInvalidString(): void
    {
        $result = $this->caster->cast('datetime', 'not-a-date-at-all');
        $this->assertNull($result);
    }

    public function testCastDateTimeImmutableWithFormat(): void
    {
        // datetime:immutable:Y-m-d - immutable with specific format
        $casted = $this->caster->cast('datetime:immutable:Y-m-d', '2023-06-15');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertEquals('2023-06-15', $casted->format('Y-m-d'));

        // datetime:Y-m-d:immutable - order doesn't matter
        $casted2 = $this->caster->cast('datetime:Y-m-d:immutable', '2023-06-15');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted2);
        $this->assertEquals('2023-06-15', $casted2->format('Y-m-d'));
    }

    public function testCastDateTimeImmutableFromTimestamp(): void
    {
        $timestamp = 1672531200;
        $casted = $this->caster->cast('datetime:immutable', $timestamp);
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertEquals($timestamp, $casted->getTimestamp());
    }

    public function testCastDateTimeFromDateTimeImmutable(): void
    {
        $immutable = new DateTimeImmutable('2023-01-01');
        $result = $this->caster->cast('datetime', $immutable);
        $this->assertSame($immutable, $result);
    }

    public function testCastDateTimeFromInvalidType(): void
    {
        $result = $this->caster->cast('datetime', ['invalid']);
        $this->assertNull($result);
    }

    public function testCastUnknownTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cast type: unknown');
        $this->caster->cast('unknown', 'value');
    }

    // ===== datetime specs =====

    #[DataProvider('dateTimeSpecProvider')]
    public function testParseDateTimeSpec(string $spec, bool $immutable, ?string $format): void
    {
        $this->assertSame(['immutable' => $immutable, 'format' => $format], BuiltInCaster::parseDateTimeSpec($spec));
    }

    /** @return array<string, array{0: string, 1: bool, 2: ?string}> */
    public static function dateTimeSpecProvider(): array
    {
        return [
            'bare'                => ['datetime', false, null],
            'immutable'           => ['datetime:immutable', true, null],
            'format'              => ['datetime:Y-m-d', false, 'Y-m-d'],
            'format with colons'  => ['datetime:Y-m-d H:i:s', false, 'Y-m-d H:i:s'],
            'immutable + format'  => ['datetime:immutable:Y-m-d H:i:s', true, 'Y-m-d H:i:s'],
            'format + immutable'  => ['datetime:H:i:immutable', true, 'H:i'],
        ];
    }

    public function testDateTimeSpecNeedsColonAfterPrefix(): void
    {
        $this->assertTrue(BuiltInCaster::isDateTime('datetime'));
        $this->assertTrue(BuiltInCaster::isDateTime('datetime:Y-m-d'));
        $this->assertFalse(BuiltInCaster::isDateTime('datetimeX'));
        $this->assertFalse(BuiltInCaster::isBuiltIn('datetime_immutable'));
    }

    public function testCastDateTimeFormatContainingColons(): void
    {
        // Regression: the spec used to be split on ':' and the format collapsed to 's'.
        $casted = $this->caster->cast('datetime:immutable:Y-m-d H:i:s', '2024-01-15 10:30:45');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-15 10:30:45', $casted->format('Y-m-d H:i:s'));

        $casted = $this->caster->cast('datetime:Y-m-d H:i:s', '2024-01-15 10:30:45');
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertSame('2024-01-15 10:30:45', $casted->format('Y-m-d H:i:s'));
    }

    public function testDateOnlyFormatYieldsMidnight(): void
    {
        // Regression: createFromFormat() filled the time of day from "now".
        $casted = $this->caster->cast('datetime:Y-m-d', '2024-01-15');
        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertSame('2024-01-15 00:00:00', $casted->format('Y-m-d H:i:s'));

        $casted = $this->caster->cast('datetime:immutable:d.m.Y', '15.01.2024');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-15 00:00:00', $casted->format('Y-m-d H:i:s'));
    }

    public function testPartialDateFormatsResetOnlyTheTime(): void
    {
        // Review #2: fields the format does not parse keep PHP's semantics
        // (current year for `m-d`) — only the time of day is reset.
        $currentYear = date('Y');
        $casted = $this->caster->cast('datetime:immutable:m-d', '05-17');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame($currentYear . '-05-17 00:00:00', $casted->format('Y-m-d H:i:s'));

        $casted = $this->caster->cast('datetime:immutable:Y-m', '2024-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01', $casted->format('Y-m'));
        $this->assertSame('00:00:00', $casted->format('H:i:s'));

        $casted = $this->caster->cast('datetime:immutable:!Y-m', '2024-01');
        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame('2024-01-01 00:00:00', $casted->format('Y-m-d H:i:s'));
    }

    public function testImmutableSpecConvertsMutableInstance(): void
    {
        $mutable = new DateTime('2024-01-15 10:30:00');

        $casted = $this->caster->cast('datetime:immutable', $mutable);

        $this->assertInstanceOf(DateTimeImmutable::class, $casted);
        $this->assertSame($mutable->getTimestamp(), $casted->getTimestamp());
    }

    public function testNumericStringIsReadAsTimestamp(): void
    {
        $casted = $this->caster->cast('datetime', '1700000000');

        $this->assertInstanceOf(DateTime::class, $casted);
        $this->assertSame(1700000000, $casted->getTimestamp());
    }

    // ===== arrays into scalars =====

    public function testRejectsArray(): void
    {
        foreach (['int', 'integer', 'float', 'double', 'bool', 'boolean', 'INT'] as $type) {
            $this->assertTrue(BuiltInCaster::rejectsArray($type), $type);
        }
        foreach (['string', 'array', 'datetime'] as $type) {
            $this->assertFalse(BuiltInCaster::rejectsArray($type), $type);
        }
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
