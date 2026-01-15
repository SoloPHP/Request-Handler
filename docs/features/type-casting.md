# Type Casting

The library automatically casts input values based on property types or explicit `cast` parameter.

## Automatic Casting

When no explicit `cast` is specified, values are cast based on property type:

```php
public int $count;      // Cast to int
public float $price;    // Cast to float
public bool $active;    // Cast to bool
public string $name;    // Cast to string
public array $tags;     // Cast to array
```

## Explicit Casting

Use the `cast` parameter for explicit control:

```php
#[Field(cast: 'int')]
public int $quantity;

#[Field(cast: 'float')]
public float $amount;

#[Field(cast: 'bool')]
public bool $enabled;

#[Field(cast: 'array')]
public array $items;
```

---

## Built-in Type Casting

### Integer

```php
$this->caster->cast('int', '123');    // 123
$this->caster->cast('int', true);     // 1
$this->caster->cast('int', false);    // 0
$this->caster->cast('int', 'abc');    // 0
```

### Float

```php
$this->caster->cast('float', '12.34'); // 12.34
$this->caster->cast('float', '100');   // 100.0
$this->caster->cast('float', true);    // 1.0
```

### Boolean

| Input | Result |
|-------|--------|
| `true`, `"true"`, `"1"`, `"on"`, `"yes"` | `true` |
| `false`, `"false"`, `"0"`, `"off"`, `"no"`, `""` | `false` |
| Any other non-empty string | `true` |

```php
$this->caster->cast('bool', 'yes');   // true
$this->caster->cast('bool', 'no');    // false
$this->caster->cast('bool', '1');     // true
$this->caster->cast('bool', '');      // false
```

### String

```php
$this->caster->cast('string', 123);           // "123"
$this->caster->cast('string', ['a' => 1]);    // '{"a":1}'
```

### Array

Smart conversion logic:

1. **JSON**: Valid JSON array/object is decoded
2. **CSV**: Comma-separated string is split
3. **Single**: Non-empty string wrapped in array
4. **Empty**: Empty string becomes `[]`

```php
$this->caster->cast('array', '["a","b"]');    // ["a", "b"]
$this->caster->cast('array', 'a, b, c');      // ["a", "b", "c"]
$this->caster->cast('array', 'single');       // ["single"]
$this->caster->cast('array', '');             // []
```

---

## DateTime Casting

### Basic DateTime

```php
#[Field(cast: 'datetime')]
public DateTime $createdAt;

// Accepts: ISO strings, timestamps, DateTime objects
// "2024-01-15" → DateTime
// 1705276800 → DateTime
```

### DateTime with Format

```php
#[Field(cast: 'datetime:Y-m-d')]
public DateTime $birthDate;

#[Field(cast: 'datetime:Y-m-d H:i:s')]
public DateTime $eventTime;
```

### DateTimeImmutable

```php
#[Field(cast: 'datetime:immutable')]
public DateTimeImmutable $timestamp;

#[Field(cast: 'datetime:immutable:Y-m-d')]
public DateTimeImmutable $date;
```

---

## Custom Casters

Implement `CasterInterface` for complex types:

```php
use Solo\RequestHandler\Contracts\CasterInterface;

final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value): Money
    {
        // Convert cents to Money object
        return new Money((int) round((float) $value * 100));
    }
}
```

Usage:

```php
#[Field(cast: MoneyCaster::class)]
public Money $price;
```

### Caster with Dependencies

Register caster instance for dependency injection:

```php
final class CurrencyCaster implements CasterInterface
{
    public function __construct(
        private readonly CurrencyConverter $converter
    ) {}

    public function cast(mixed $value): Money
    {
        return $this->converter->convert($value);
    }
}

// Register
$handler->register(CurrencyCaster::class, new CurrencyCaster($converter));
```

---

## PostProcessor Skips Auto-Cast

When `postProcess` is defined, automatic casting is skipped:

```php
// Without postProcess: auto-cast converts JSON to ['["a","b"]']
// With postProcess: you handle the conversion

#[Field(postProcess: JsonDecoder::class)]
public array $tags;

final class JsonDecoder implements ProcessorInterface
{
    public function process(mixed $value): array
    {
        return json_decode($value, true) ?? [];
    }
}
```

---

## Null Handling

Null and empty string values return `null` for all built-in casters:

```php
$this->caster->cast('int', null);     // null
$this->caster->cast('int', '');       // null
$this->caster->cast('string', null);  // null
```

---

## Type Safety

Configuration is validated at build time:

```php
// ❌ Error: cast type incompatible with property type
#[Field(cast: 'string')]
public int $id;

// ✅ Valid: types match
#[Field(cast: 'int')]
public int $id;

// ✅ Valid: float is compatible with int|float
#[Field(cast: 'float')]
public int|float $price;
```
