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
        foreach (['int', 'float', 'bool', 'string', 'array', 'json'] as $type) {
            $this->assertTrue(BuiltInCaster::isBuiltIn($type), $type);
        }
        // 4.0: no aliases, no datetime specs.
        foreach (['integer', 'double', 'boolean', 'datetime', 'MyClass'] as $type) {
            $this->assertFalse(BuiltInCaster::isBuiltIn($type), $type);
        }
    }

    public function testCastIntIsLossless(): void
    {
        $this->assertSame(123, $this->caster->cast('int', '123'));
        $this->assertSame(-5, $this->caster->cast('int', '-5'));
        $this->assertSame(7, $this->caster->cast('int', 7));
        $this->assertSame(2, $this->caster->cast('int', 2.0));

        // Cannot be converted without loss → null (the handler reports `integer`).
        // A boolean is no number either.
        foreach (['abc', '12abc', '1.5', '1e3', 'true', [5], 2.5, 1.0E20, true, false] as $input) {
            $this->assertNull($this->caster->cast('int', $input), var_export($input, true));
        }
    }

    public function testCastFloatIsLossless(): void
    {
        $this->assertSame(12.34, $this->caster->cast('float', '12.34'));
        $this->assertSame(1000.0, $this->caster->cast('float', '1e3'));
        $this->assertSame(5.0, $this->caster->cast('float', 5));

        // Overflow to INF / NAN is no number either.
        foreach (['abc', '1,5', [1.5], 'NaN', true, false, '1e400', '-1e400', INF, -INF, NAN] as $input) {
            $this->assertNull($this->caster->cast('float', $input), var_export($input, true));
        }
    }

    public function testCastBoolAcceptsOnlyBooleanForms(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', 1, true] as $input) {
            $this->assertTrue($this->caster->cast('bool', $input), var_export($input, true));
        }
        foreach (['false', '0', 'no', 'Off', 0, false] as $input) {
            $this->assertFalse($this->caster->cast('bool', $input), var_export($input, true));
        }
        foreach (['anything', 2, 'null', [true], 1.0] as $input) {
            $this->assertNull($this->caster->cast('bool', $input), var_export($input, true));
        }
    }

    public function testCastStringAcceptsScalarsAndStringables(): void
    {
        $this->assertSame('abc', $this->caster->cast('string', 'abc'));
        $this->assertSame('123', $this->caster->cast('string', 123));
        $this->assertSame('1.5', $this->caster->cast('string', 1.5));
        $this->assertSame('test-object', $this->caster->cast('string', new class {
            public function __toString(): string
            {
                return 'test-object';
            }
        }));

        foreach ([['a' => 1], true, new \stdClass(), INF, NAN] as $input) {
            $this->assertNull($this->caster->cast('string', $input), var_export($input, true));
        }
    }

    public function testCastJsonEncodesStructuredInput(): void
    {
        $this->assertSame('{"a":1}', $this->caster->cast('json', ['a' => 1]));
        $this->assertSame('[1,2]', $this->caster->cast('json', [1, 2]));
        $this->assertSame('{"x":true}', $this->caster->cast('json', '{"x":true}'));
        $this->assertSame('true', $this->caster->cast('json', true));
    }

    public function testCastArray(): void
    {
        $this->assertSame(['a' => 1], $this->caster->cast('array', ['a' => 1]));
        $this->assertSame(['a', 'b'], $this->caster->cast('array', '["a","b"]'));
        $this->assertSame(['a', 'b'], $this->caster->cast('array', 'a,b'));
        $this->assertSame(['val'], $this->caster->cast('array', 'val'));
    }

    public function testCastArrayFromTraversableReadsItsItems(): void
    {
        $generator = (static function (): \Generator {
            yield 'a';
            yield 'b';
        })();

        $this->assertSame(['a', 'b'], $this->caster->cast('array', $generator));
        $this->assertSame(['k' => 1], $this->caster->cast('array', new \ArrayIterator(['k' => 1])));
    }

    public function testCastArrayFromTraversableKeepsEveryItem(): void
    {
        // iterator_to_array() would keep the keys and return ['c', 'd'].
        $chained = (static function (): \Generator {
            yield from ['a', 'b'];
            yield from ['c', 'd'];
        })();
        $this->assertSame(['a', 'b', 'c', 'd'], $this->caster->cast('array', $chained));

        $repeated = (static function (): \Generator {
            yield 'k' => 1;
            yield 'k' => 2;
        })();
        $this->assertSame([1, 2], $this->caster->cast('array', $repeated));

        $objectKeys = (static function (): \Generator {
            yield new \stdClass() => 'x';
        })();
        $this->assertSame(['x'], $this->caster->cast('array', $objectKeys));
    }

    public function testIterableCastKeepsAnIterable(): void
    {
        $generator = (static function (): \Generator {
            yield 'a';
        })();

        $this->assertSame($generator, $this->caster->cast('iterable', $generator), 'not consumed');
        $this->assertSame(['a'], $this->caster->cast('iterable', ['a']));
        $this->assertSame(['a', 'b'], $this->caster->cast('iterable', 'a,b'));
        $this->assertFalse(BuiltInCaster::isBuiltIn('iterable'), 'no #[Cast] vocabulary');
    }

    public function testCastArrayFromObject(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        $this->assertSame(['key' => 'value'], $this->caster->cast('array', $obj));
    }

    public function testCastNull(): void
    {
        foreach (['int', 'float', 'bool', 'string', 'array', 'json'] as $type) {
            $this->assertNull($this->caster->cast($type, null), $type);
        }
    }

    public function testEmptyStringMeansNoValueForNumbersAndBooleans(): void
    {
        $this->assertNull($this->caster->cast('int', ''));
        $this->assertNull($this->caster->cast('float', ''));
        $this->assertNull($this->caster->cast('bool', ''));
        $this->assertSame('', $this->caster->cast('string', ''));
        $this->assertSame([], $this->caster->cast('array', ''));
    }

    public function testCastUnknownTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cast type: unknown');
        $this->caster->cast('unknown', 'value');
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

    public function testDigitsAreADateOrATimestampButNeverATimeOfDay(): void
    {
        $compact = $this->caster->castDate(DateTimeImmutable::class, null, '20240115');
        $this->assertInstanceOf(DateTimeImmutable::class, $compact);
        $this->assertSame('2024-01-15 00:00:00', $compact->format('Y-m-d H:i:s'));

        // The parser would read these as 20:24, 12:30 and 20:24:01 today.
        foreach (['2024', '1230', '202401'] as $digits) {
            $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, $digits), $digits);
        }

        // Other strings keep the parser's meaning, a time of day included.
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, null, '10:30'));
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, null, 'tomorrow'));
    }

    public function testImpossibleDatesAreNotRolledOver(): void
    {
        // PHP's parser turns these into 2024-03-02, 2023-03-01, … with a warning.
        foreach (['2024-02-31', '2023-02-29', '20240231', '2024-01-15 24:00:00'] as $input) {
            $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, $input), $input);
        }
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d', '2024-02-31'));
        $this->assertNull($this->caster->castDate(DateTime::class, 'Y-m-d H:i', '2024-01-15 23:61'));

        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, null, '2024-02-29'));
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, 'd.m.Y', '29.02.2024'));
        // A `+` format allows trailing data — a warning, but no rolled-over date.
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d+', '2024-01-15 extra'));
    }

    public function testFormatAppliesToANumberToo(): void
    {
        $compact = $this->caster->castDate(DateTimeImmutable::class, 'Ymd', 20240115);
        $this->assertInstanceOf(DateTimeImmutable::class, $compact);
        $this->assertSame('2024-01-15 00:00:00', $compact->format('Y-m-d H:i:s'));

        $timestamp = $this->caster->castDate(DateTimeImmutable::class, 'U', 1700000000);
        $this->assertInstanceOf(DateTimeImmutable::class, $timestamp);
        $this->assertSame(1700000000, $timestamp->getTimestamp());

        // Not a timestamp behind the format's back.
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d', 1700000000));
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
