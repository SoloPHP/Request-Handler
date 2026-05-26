# Type Casting

The library automatically casts input values based on property types, with optional `#[Cast]` and `#[Caster]` attributes for explicit control.

## Automatic Casting (driven by declared type)

When no `#[Cast]` or `#[Caster]` is specified, values are cast based on the property type:

```php
public int $count;      // Cast to int
public float $price;    // Cast to float
public bool $active;    // Cast to bool
public string $name;    // Cast to string
public array $tags;     // Cast to array
```

## Explicit Casting (`#[Cast]`)

Use `#[Cast]` for built-in casts when the property type alone isn't enough (e.g. `DateTime` with a format):

```php
use Solo\RequestHandler\Attributes\Cast;

#[Cast('int')]
public int $quantity;

#[Cast('float')]
public float $amount;

#[Cast('bool')]
public bool $enabled;

#[Cast('array')]
public array $items;
```

---

## Built-in Type Casting

### Integer

```php
$caster->cast('int', '123');    // 123
$caster->cast('int', true);     // 1
$caster->cast('int', false);    // 0
$caster->cast('int', 'abc');    // 0
```

### Float

```php
$caster->cast('float', '12.34'); // 12.34
$caster->cast('float', '100');   // 100.0
$caster->cast('float', true);    // 1.0
```

### Boolean

| Input | Result |
|-------|--------|
| `true`, `"true"`, `"1"`, `"on"`, `"yes"` | `true` |
| `false`, `"false"`, `"0"`, `"off"`, `"no"`, `""` | `false` |
| Any other non-empty string | `true` |

```php
$caster->cast('bool', 'yes');   // true
$caster->cast('bool', 'no');    // false
$caster->cast('bool', '1');     // true
$caster->cast('bool', '');      // false
```

### String

```php
$caster->cast('string', 123);           // "123"
$caster->cast('string', ['a' => 1]);    // '{"a":1}'
```

### Array

Smart conversion logic:

1. **JSON**: Valid JSON array/object is decoded
2. **CSV**: Comma-separated string is split
3. **Single**: Non-empty string wrapped in array
4. **Empty**: Empty string becomes `[]`

```php
$caster->cast('array', '["a","b"]');    // ["a", "b"]
$caster->cast('array', 'a, b, c');      // ["a", "b", "c"]
$caster->cast('array', 'single');       // ["single"]
$caster->cast('array', '');             // []
```

---

## DateTime Casting

### Basic DateTime

```php
#[Cast('datetime')]
public DateTime $createdAt;

// Accepts: ISO strings, timestamps, DateTime objects
// "2024-01-15" → DateTime
// 1705276800 → DateTime
```

### DateTime with Format

```php
#[Cast('datetime:Y-m-d')]
public DateTime $birthDate;

#[Cast('datetime:Y-m-d H:i:s')]
public DateTime $eventTime;
```

### DateTimeImmutable

```php
#[Cast('datetime:immutable')]
public DateTimeImmutable $timestamp;

#[Cast('datetime:immutable:Y-m-d')]
public DateTimeImmutable $date;
```

---

## Custom Casters (`#[Caster]`)

Implement `CasterInterface` for complex types and plug them in via `#[Caster]`:

```php
use Solo\RequestHandler\Attributes\Caster;
use Solo\RequestHandler\Contracts\CasterInterface;

final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value): Money
    {
        return new Money((int) round((float) $value * 100));
    }
}
```

Usage:

```php
#[Caster(MoneyCaster::class)]
public Money $price;
```

`#[Cast]` and `#[Caster]` are mutually exclusive — declaring both on the same property throws `ConfigurationException`.

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

When `#[PostProcess]` is defined, automatic casting is skipped (the processor is responsible for the final value):

```php
#[PostProcess(JsonDecoder::class)]
public array $tags;

final class JsonDecoder implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): array
    {
        return json_decode($value, true) ?? [];
    }
}
```

---

## Null and Empty String Handling

`null` returns `null` for all built-in casters:

```php
$caster->cast('int', null);     // null
$caster->cast('string', null);  // null
$caster->cast('array', null);   // null
```

Empty string `""` is handled per type:

| Type | `""` Result | Reason |
|------|-------------|--------|
| `int`, `float` | `null` | Not a valid number |
| `datetime` | `null` | Not a valid date |
| `string` | `""` | Preserved as-is |
| `bool` | `false` | Treated as falsy |
| `array` | `[]` | Empty collection |

```php
$caster->cast('int', '');       // null
$caster->cast('string', '');    // ""
$caster->cast('bool', '');      // false
$caster->cast('array', '');     // []
```

---

## Type Safety

Configuration is validated at build time:

```php
// ❌ Error: cast type incompatible with property type
#[Cast('string')]
public int $id;

// ✅ Valid: types match
#[Cast('int')]
public int $id;

// ✅ Valid: float is compatible with int|float
#[Cast('float')]
public int|float $price;
```
