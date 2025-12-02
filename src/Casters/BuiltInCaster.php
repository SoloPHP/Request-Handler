<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Casters;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Handles built-in type casting
 */
final class BuiltInCaster
{
    private const BUILT_IN_TYPES = [
        'int' => true,
        'integer' => true,
        'float' => true,
        'double' => true,
        'bool' => true,
        'boolean' => true,
        'string' => true,
        'array' => true
    ];

    public function isBuiltIn(string $type): bool
    {
        return isset(self::BUILT_IN_TYPES[strtolower($type)])
            || str_starts_with($type, 'datetime');
    }

    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (str_starts_with($type, 'datetime')) {
            return $this->handleDateTime($type, $value);
        }

        return match (strtolower($type)) {
            'int', 'integer' => $this->toInt($value),
            'float', 'double' => $this->toFloat($value),
            'bool', 'boolean' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
            default => throw new InvalidArgumentException("Unknown cast type: {$type}"),
        };
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
            // Comma-separated string
            if (str_contains($value, ',')) {
                return array_map('trim', explode(',', $value));
            }
            return $value !== '' ? [$value] : [];
        }
        return (array) $value;
    }

    private function handleDateTime(string $type, mixed $value): DateTime|DateTimeImmutable|null
    {
        if ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        // Check for immutable variant
        $useImmutable = str_contains($type, 'immutable');
        $class = $useImmutable ? DateTimeImmutable::class : DateTime::class;

        // Extract format (everything after 'datetime:' except 'immutable')
        $format = null;
        if (($colonPos = strpos($type, ':')) !== false) {
            $format = preg_replace('/\bimmutable\b:?|:?\bimmutable\b/', '', substr($type, $colonPos + 1));
            $format = $format !== '' ? $format : null;
        }

        if (is_int($value)) {
            return (new $class())->setTimestamp($value);
        }

        if ($format !== null) {
            $parsed = $class::createFromFormat($format, $value);
            return $parsed !== false ? $parsed : null;
        }

        try {
            return new $class($value);
        } catch (\Exception) {
            return null;
        }
    }
}
