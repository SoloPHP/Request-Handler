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
    private const BUILT_IN_TYPES = ['int', 'integer', 'float', 'double', 'bool', 'boolean', 'string', 'array'];

    public function isBuiltIn(string $type): bool
    {
        $baseType = $this->extractBaseType($type);
        return in_array($baseType, self::BUILT_IN_TYPES, true)
            || str_starts_with($baseType, 'datetime');
    }

    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $baseType = $this->extractBaseType($type);

        return match ($baseType) {
            'int', 'integer' => $this->toInt($value),
            'float', 'double' => $this->toFloat($value),
            'bool', 'boolean' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
            default => $this->handleDateTime($type, $value),
        };
    }

    private function extractBaseType(string $type): string
    {
        // Handle datetime:format
        if (str_starts_with($type, 'datetime')) {
            return 'datetime';
        }
        return strtolower($type);
    }

    private function toInt(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        return 0.0;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return (bool) $value;
    }

    private function toString(mixed $value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
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
        if (!str_starts_with($type, 'datetime')) {
            throw new InvalidArgumentException("Unknown cast type: {$type}");
        }

        if ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        // Check for immutable variant
        $useImmutable = str_contains($type, 'immutable');
        $class = $useImmutable ? DateTimeImmutable::class : DateTime::class;

        // Extract format if specified: datetime:Y-m-d or datetime:immutable:Y-m-d
        $format = null;
        if (str_contains($type, ':')) {
            // Remove 'datetime' prefix and 'immutable' keyword to get format
            $parts = explode(':', $type);
            array_shift($parts); // remove 'datetime'
            $parts = array_filter($parts, fn($p) => $p !== 'immutable');
            $format = !empty($parts) ? implode(':', $parts) : null;
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
