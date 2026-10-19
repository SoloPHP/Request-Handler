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
     * Scalar cast types: the `#[Cast]` vocabulary and the type-driven casts of
     * `int` / `float` / `bool` / `string` / `array` properties.
     */
    public const array SCALAR_TYPES = ['int', 'float', 'bool', 'string', 'array'];

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
        return in_array($type, self::SCALAR_TYPES, true);
    }

    /**
     * Whether an array input is meaningless for a scalar cast: `(int) ['5']`
     * is 1, `(bool) ['x']` is true. String (JSON-encodes) and array casts
     * accept arrays.
     */
    public static function rejectsArray(string $type): bool
    {
        return $type === 'int' || $type === 'float' || $type === 'bool';
    }

    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => $value === '' ? null : $this->toInt($value),
            'float' => $value === '' ? null : $this->toFloat($value),
            'bool' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
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

    private function toInt(mixed $value): int
    {
        return is_bool($value) ? ($value ? 1 : 0) : (int) $value;
    }

    private function toFloat(mixed $value): float
    {
        return is_bool($value) ? ($value ? 1.0 : 0.0) : (float) $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => (bool) $value,
            };
        }
        return (bool) $value;
    }

    private function toString(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        return '';
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
