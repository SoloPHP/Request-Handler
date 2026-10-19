<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Casters;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * Handles built-in type casting
 *
 * @internal
 */
final class BuiltInCaster
{
    private const array BUILT_IN_TYPES = [
        'int' => true,
        'integer' => true,
        'float' => true,
        'double' => true,
        'bool' => true,
        'boolean' => true,
        'string' => true,
        'array' => true
    ];

    /**
     * Memoised parse of `datetime[:immutable][:format]` strings.
     *
     * @var array<string, array{class: class-string<DateTime|DateTimeImmutable>, format: ?string, midnight: bool}>
     */
    private array $datetimeSpecCache = [];

    /**
     * Memoised "is this enum int-backed" lookups.
     *
     * @var array<class-string<BackedEnum>, bool>
     */
    private array $enumIntBacked = [];

    public static function isBuiltIn(string $type): bool
    {
        return isset(self::BUILT_IN_TYPES[strtolower($type)]) || self::isDateTime($type);
    }

    /**
     * Whether `$type` is a datetime spec: `datetime` or `datetime:<options>`.
     */
    public static function isDateTime(string $type): bool
    {
        return $type === 'datetime' || str_starts_with($type, 'datetime:');
    }

    /**
     * Whether an array input is meaningless for a built-in cast: `(int) ['5']`
     * is 1, `(bool) ['x']` is true. String (JSON-encodes) and array casts
     * accept arrays; datetime casts reject them by failing.
     */
    public static function rejectsArray(string $type): bool
    {
        $type = strtolower($type);

        return isset(self::BUILT_IN_TYPES[$type]) && $type !== 'string' && $type !== 'array';
    }

    /**
     * Parse a `datetime[:immutable][:format]` spec.
     *
     * The format may contain `:` itself (`datetime:Y-m-d H:i:s`), so only an
     * `immutable` token at the very start or very end of the options counts as
     * the flag — everything else is the format.
     *
     * @return array{immutable: bool, format: ?string}
     */
    public static function parseDateTimeSpec(string $type): array
    {
        $options = substr($type, strlen('datetime:'));
        $immutable = true;

        if ($options === 'immutable') {
            $options = '';
        } elseif (str_starts_with($options, 'immutable:')) {
            $options = substr($options, strlen('immutable:'));
        } elseif (str_ends_with($options, ':immutable')) {
            $options = substr($options, 0, -strlen(':immutable'));
        } else {
            $immutable = false;
        }

        return ['immutable' => $immutable, 'format' => $options === '' ? null : $options];
    }

    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (self::isDateTime($type)) {
            return $value === '' ? null : $this->handleDateTime($type, $value);
        }

        return match (strtolower($type)) {
            'int', 'integer' => $value === '' ? null : $this->toInt($value),
            'float', 'double' => $value === '' ? null : $this->toFloat($value),
            'bool', 'boolean' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
            default => throw new InvalidArgumentException("Unknown cast type: {$type}"),
        };
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

    private function handleDateTime(string $type, mixed $value): DateTime|DateTimeImmutable|null
    {
        $spec = $this->datetimeSpecCache[$type] ??= $this->compileDateTimeSpec($type);
        $class = $spec['class'];

        // An explicit `immutable` spec must yield an immutable instance; the
        // default spec passes any DateTime object through untouched.
        if ($value instanceof DateTime) {
            return $class === DateTimeImmutable::class ? DateTimeImmutable::createFromMutable($value) : $value;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        if (is_int($value)) {
            return (new $class())->setTimestamp($value);
        }

        if ($spec['format'] !== null) {
            $parsed = $class::createFromFormat($spec['format'], $value);
            if ($parsed === false) {
                return null;
            }
            return $spec['midnight'] ? $parsed->setTime(0, 0) : $parsed;
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
     * @return array{class: class-string<DateTime|DateTimeImmutable>, format: ?string, midnight: bool}
     */
    private function compileDateTimeSpec(string $type): array
    {
        ['immutable' => $immutable, 'format' => $format] = self::parseDateTimeSpec($type);

        return [
            'class' => $immutable ? DateTimeImmutable::class : DateTime::class,
            'format' => $format,
            'midnight' => $format !== null && self::parsesNoTime($format),
        ];
    }

    /**
     * Whether a format parses no time-of-day field and has no explicit reset
     * (`!` / `|`). `createFromFormat()` fills unparsed fields from the current
     * time, so `Y-m-d` would otherwise carry "now" as its time of day. Only the
     * time is reset: unparsed date fields keep PHP's semantics (`m-d` is in
     * the current year).
     */
    private static function parsesNoTime(string $format): bool
    {
        $unescaped = preg_replace('/\\\\./s', '', $format) ?? $format;

        return strpbrk($unescaped, 'aAgGhHisuvU!|') === false;
    }
}
