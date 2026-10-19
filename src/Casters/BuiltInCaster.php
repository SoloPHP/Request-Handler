<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Casters;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * Handles built-in type casting
 *
 * @internal
 */
final class BuiltInCaster
{
    /**
     * Built-in cast types: the `#[Cast]` vocabulary and the type-driven casts
     * of `int` / `float` / `bool` / `string` / `array` properties. `json`
     * (explicit only) encodes a structured value into a string property.
     */
    public const array CAST_TYPES = ['int', 'float', 'bool', 'string', 'array', 'json'];

    private const array TRUE_STRINGS = ['true' => true, '1' => true, 'yes' => true, 'on' => true];
    private const array FALSE_STRINGS = ['false' => true, '0' => true, 'no' => true, 'off' => true];

    /**
     * Memoised "does this format parse no time-of-day field" lookups.
     *
     * @var array<string, bool>
     */
    private array $midnightFormats = [];

    /**
     * Memoised "is this enum int-backed" lookups.
     *
     * @var array<class-string<BackedEnum>, bool>
     */
    private array $enumIntBacked = [];

    public static function isBuiltIn(string $type): bool
    {
        return in_array($type, self::CAST_TYPES, true);
    }

    /**
     * Convert `$value` to `$type` without losing information — or return null
     * when it cannot be: `'12abc'` is no int, `['5']` no float, `'maybe'` no
     * bool. `""` means "no value" for int / float / bool (null); a string
     * keeps it, an array reads it as `[]`.
     */
    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => $this->toInt($value),
            'float' => $this->toFloat($value),
            'bool' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
            'json' => $this->toJson($value),
            default => throw new InvalidArgumentException("Unknown cast type: {$type}"),
        };
    }

    /**
     * Build a `$class` date from a string (in `$format` when given), a Unix
     * timestamp or another DateTime object. Null when the value cannot be
     * read as a date — and for "" (no value).
     *
     * A format without time-of-day fields yields midnight: `createFromFormat()`
     * would otherwise copy the current time into the unparsed fields.
     *
     * @param class-string<DateTime|DateTimeImmutable> $class
     */
    public function castDate(string $class, ?string $format, mixed $value): DateTime|DateTimeImmutable|null
    {
        if ($value instanceof $class) {
            /** @var DateTime|DateTimeImmutable $value */
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $class::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new $class())->setTimestamp($value);
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        if ($format !== null) {
            $parsed = $class::createFromFormat($format, $value);
            if ($parsed === false) {
                return null;
            }

            return ($this->midnightFormats[$format] ??= self::parsesNoTime($format))
                ? $parsed->setTime(0, 0)
                : $parsed;
        }

        try {
            return new $class($value);
        } catch (\Exception) {
            // The DateTime parser rejects a bare Unix timestamp ('1700000000'),
            // which is how query strings and form fields deliver one.
            return preg_match('/^-?\d+$/', $value) === 1 ? (new $class())->setTimestamp((int) $value) : null;
        }
    }

    /**
     * Resolve a BackedEnum case from its backing value. Numeric strings are
     * accepted for int-backed enums (query strings carry no ints).
     *
     * @template TEnum of BackedEnum
     * @param class-string<TEnum> $enumClass
     * @return TEnum|null Null when the value matches no case.
     */
    public function castEnum(string $enumClass, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        $intBacked = $this->enumIntBacked[$enumClass]
            ??= (string) (new ReflectionEnum($enumClass))->getBackingType() === 'int';

        if ($intBacked) {
            $int = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : $value;
            return is_int($int) ? $enumClass::tryFrom($int) : null;
        }

        return is_string($value) || is_int($value) ? $enumClass::tryFrom((string) $value) : null;
    }

    private function toInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_bool($value) => (int) $value,
            // Whole floats only, inside the range a float can hold exactly.
            is_float($value) => $value === floor($value) && abs($value) < 9.0E15 ? (int) $value : null,
            is_string($value) => ($int = filter_var($value, FILTER_VALIDATE_INT)) === false ? null : $int,
            default => null,
        };
    }

    private function toFloat(mixed $value): ?float
    {
        return match (true) {
            is_float($value), is_int($value), is_bool($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => null,
        };
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }

        $lower = strtolower($value);

        return match (true) {
            isset(self::TRUE_STRINGS[$lower]) => true,
            isset(self::FALSE_STRINGS[$lower]) => false,
            default => null,
        };
    }

    private function toString(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    private function toJson(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value);

        return $json === false ? null : $json;
    }

    /**
     * @return array<mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            if (str_contains($value, ',')) {
                return array_map('trim', explode(',', $value));
            }
            return $value !== '' ? [$value] : [];
        }
        return (array) $value;
    }

    /**
     * Whether a format parses no time-of-day field and has no explicit reset
     * (`!` / `|`). Only the time is reset: unparsed date fields keep PHP's
     * semantics (`m-d` is in the current year).
     */
    private static function parsesNoTime(string $format): bool
    {
        $unescaped = preg_replace('/\\\\./s', '', $format) ?? $format;

        return strpbrk($unescaped, 'aAgGhHisuvU!|') === false;
    }
}
