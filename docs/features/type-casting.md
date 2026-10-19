# Type Casting

The library automatically casts input values based on property types, with optional `#[Cast]` and `#[Caster]` attributes for explicit control.

## Automatic Casting (driven by declared type)

When no `#[Cast]` or `#[Caster]` is specified, values are cast based on the property type:

```php
public int $count;                // Cast to int
public float $price;              // Cast to float
public bool $active;              // Cast to bool
public string $name;              // Cast to string
public array $tags;               // Cast to array
public DateTimeImmutable $at;     // Parsed into DateTimeImmutable (also for DateTimeInterface)
public DateTime $legacyAt;        // Parsed into DateTime
public Status $status;            // BackedEnum — resolved by backing value
```

## Cast Failures Are Validation Errors

A value that cannot become the declared type is reported in the `ValidationException`, together with the validator's errors, instead of turning into `1`, `null` or an uninitialised property:

| Input | Error |
|-------|-------|
| Array for an `int` / `float` / `bool` property (`?page[]=5`) | `['rule' => 'scalar']` |
| Unparseable date, no format | `['rule' => 'date']` |
| Unparseable date for `#[DateFormat('<format>')]` | `['rule' => 'date_format', 'params' => ['<format>']]` |
| Value matching no enum case | `['rule' => 'in', 'params' => [...backing values]]` |
| `""` or `null` for a non-nullable property without a default | `['rule' => 'required']` |

An empty string keeps its meaning "no value": it becomes `null` for `int`, `float`, DateTime and enum properties — `null` on a nullable property, the default on one that has a default, and the `required` error otherwise. A field that already failed validation is not cast, so it reports the validator's error only.

An array for a `string` property is JSON-encoded (see [String](#string)), except when it comes from a `#[FromBody]` dot-path: there it means the path stops at a sub-object, and it is a `scalar` error. Properties with a pre/post-processor or a custom caster get the value those produce, so they may accept arrays.

Values the application supplies — `#[FromContext]` and generators — are not client input: one that does not fit the declared type throws `UnexpectedValueException` instead of producing a validation error.

## Explicit Casting (`#[Cast]`)

Use `#[Cast]` when the declared type does not say which scalar to produce — a union or `mixed` property. It accepts `int`, `float`, `bool`, `string` and `array` (the 3.x aliases `integer`, `double`, `boolean` are gone); dates are cast by their type, see [Dates](#dates):

```php
use Solo\RequestHandler\Attributes\Cast;

#[Cast('float')]
public int|float $amount;

#[Cast('array')]
public mixed $payload = null;
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

## Dates

A property typed `DateTimeImmutable`, `DateTime` or `DateTimeInterface` is parsed from the input — no attribute needed. The class comes from the type; `DateTimeInterface` gets `DateTimeImmutable`:

```php
public DateTimeImmutable $createdAt;       // "2024-01-15", "2024-01-15T10:30:00+02:00", ...
public DateTime $legacyAt;                 // a mutable DateTime
public ?DateTimeInterface $seenAt = null;  // a DateTimeImmutable

// Also accepted: Unix timestamps (1705276800 or "1705276800") and DateTime
// objects passed via context — converted to the declared class.
```

### Fixed format — `#[DateFormat]`

```php
use Solo\RequestHandler\Attributes\DateFormat;

#[DateFormat('Y-m-d')]
public DateTimeImmutable $birthDate;   // "1990-05-01" → 1990-05-01 00:00:00

#[DateFormat('Y-m-d H:i:s')]
public DateTime $eventTime;            // formats may contain ':'
```

A format without time-of-day fields yields midnight. Other fields the format does not parse keep `createFromFormat()` semantics and come from the current date — `m-d` is in the current year; add `!` or `|` to the format to reset them yourself (`!Y-m` is the first of the month).

`#[DateFormat]` on an untyped or `mixed` property produces a `DateTimeImmutable`; on any other non-date type it is a `ConfigurationException`. It cannot be combined with `#[Cast]`, `#[Caster]` or `#[Items]`.

::: tip Migrating from 3.x
`#[Cast('datetime…')]` was removed in 4.0 and now throws `ConfigurationException`: drop it — the class follows the property type — and move a format into `#[DateFormat]`. See [UPGRADE.md](https://github.com/solophp/request-handler/blob/main/UPGRADE.md).
:::

---

## Enums

A property typed with a `BackedEnum` is resolved through its backing value — no attribute needed. Int-backed enums also accept numeric strings, since query strings carry no ints:

```php
enum Status: string { case Draft = 'draft'; case Published = 'published'; }
enum Priority: int { case Low = 1; case High = 2; }

public Status $status;                        // "published" → Status::Published
public Priority $priority = Priority::Low;    // "2"         → Priority::High
public ?Status $filter = null;                // ""          → null

// "archived" → ['status' => [['rule' => 'in', 'params' => ['draft', 'published']]]]
```

Enum instances passed through `context` are kept as they are. Pure (non-backed) enums still need a `#[Caster]`.

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
| date types | `null` | Not a valid date |
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
