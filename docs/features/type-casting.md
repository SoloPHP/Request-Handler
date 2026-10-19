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
public AddressRequest $address;   // Request subclass — a nested DTO (see Nested Objects)
```

An `iterable` property is cast like `array`. A property of any other class type — and one typed `object`, with an intersection, or with a union of classes only — needs something that produces it: `#[Caster]`, a pre/post-processor, a `#[Generator]` or `#[FromContext]`; otherwise building the metadata throws `ConfigurationException::unproducibleType`.

### Union types

A union with a scalar or `array` member and no `#[Cast]` keeps a value of one of its types and converts any other value to the first scalar member it fits without loss — `int`, then `float`, `string`, `bool`, PHP's own order:

```php
public int|float $amount;   // 5 → 5, "5" → 5, "5.5" → 5.5, "abc" → numeric error
public int|string $code;    // "007" → "007" (a string already fits), 2.0 → 2
public array|string $tags;  // ['a'] → ['a'], 7 → "7"
```

A value that fits no member is a validation error named after the widest member: `string`, then `numeric`, `integer`, `boolean`, `array`. `""` for a union without `string` means no value, as for a number.

The literal types `true` and `false` — alone or in a union — are reached through the bool cast, then kept only when the result is the literal:

```php
public true $acceptTerms;         // true, "1", "yes", "on" → true; "0", false → boolean error
public string|false $nickname;    // false → false, "x" → "x"
```

Casts are **lossless**: a value is converted only when nothing is lost on the way — `"42"` becomes `42`, but `"42abc"` does not become `42`. Everything else is a validation error.

## Cast Failures Are Validation Errors

A value that cannot become the declared type is reported in the `ValidationException`, together with the validator's errors, instead of being coerced into something the client did not send:

| Input | Error |
|-------|-------|
| Not an integer for an `int` property (`"12abc"`, `"1.5"`, `[5]`) | `['rule' => 'integer']` |
| Not a number for a `float` property (`"9,99"`, `[1.5]`) | `['rule' => 'numeric']` |
| Not a boolean form for a `bool` property (`"maybe"`, `2`) | `['rule' => 'boolean']` |
| An array, object or boolean for a `string` property | `['rule' => 'string']` |
| Unparseable date, no format | `['rule' => 'date']` |
| Unparseable date for `#[DateFormat('<format>')]` | `['rule' => 'date_format', 'params' => ['<format>']]` |
| Value matching no enum case | `['rule' => 'in', 'params' => [...backing values]]` |
| `""` or `null` for a non-nullable property without a default | `['rule' => 'required']` |

The `int`, `float` and `bool` casts accept the forms the `integer`, `numeric` and `boolean` rules of [solophp/validator](https://github.com/solophp/validator) accept — except that a JSON `true` / `false` is never a number — and report the same error codes. So the type alone already rejects `"12abc"`; a matching rule in `#[Validate]` stays harmless.

An empty string keeps its meaning "no value": it becomes `null` for `int`, `float`, `bool`, DateTime and enum properties — `null` on a nullable property, the default on one that has a default, and the `required` error otherwise. A field that already failed validation is not cast, so it reports the validator's error only.

An array for a `string` property is a `string` error — including a `#[FromBody]` dot-path that stops at a sub-object. To store structured input in a string property (a JSON column, say), declare [`#[Cast('json')]`](#json). Properties with a pre/post-processor or a custom caster get the value those produce, so they may accept anything.

Values the application supplies — `#[FromContext]` and generators — are not client input. They go through the same casts, but one that does not fit the declared type throws `UnexpectedValueException` instead of producing a validation error: `1.5` or `true` for an `int`, `2` for a `bool`, `true` for a `string`, an array or another class for a class-typed property. So does a `null` (or a `""` for a number) for a property that is neither nullable nor has a default, instead of leaving it uninitialised.

## Explicit Casting (`#[Cast]`)

Use `#[Cast]` when the declared type does not say what to produce — a union or `mixed` property — or to JSON-encode into a string. It accepts `int`, `float`, `bool`, `string`, `array` and `json` (the 3.x aliases `integer`, `double`, `boolean` are gone); dates are cast by their type, see [Dates](#dates):

```php
use Solo\RequestHandler\Attributes\Cast;

#[Cast('float')]
public int|float $amount;

#[Cast('array')]
public mixed $payload = null;

#[Cast('json')]
public ?string $settings = null;   // ['theme' => 'dark'] → '{"theme":"dark"}'
```

---

## Built-in Type Casting

### Integer

| Input | Result |
|-------|--------|
| `123`, `"123"`, `"-5"`, `"+7"` | `123`, `123`, `-5`, `7` |
| A whole float (`2.0`) | `2` |
| `"12abc"`, `"1.5"`, `"1e3"`, `"05"`, `2.5`, `true`, an array | `integer` error |

Strings follow `FILTER_VALIDATE_INT`: digits with an optional sign — no fraction, exponent or leading zero.

### Float

| Input | Result |
|-------|--------|
| `12.34`, `"12.34"`, `"1e3"` | `12.34`, `12.34`, `1000.0` |
| `5` | `5.0` |
| `"9,99"`, `"abc"`, `true`, an array, `"1e400"` (overflows to `INF`) | `numeric` error |

### Boolean

| Input | Result |
|-------|--------|
| `true`, `1`, `"true"`, `"1"`, `"yes"`, `"on"` (any case) | `true` |
| `false`, `0`, `"false"`, `"0"`, `"no"`, `"off"` (any case) | `false` |
| `""` | no value — `null`, the default, or `required` |
| Anything else (`"maybe"`, `2`, `1.0`) | `boolean` error |

### String

| Input | Result |
|-------|--------|
| `"abc"` | `"abc"` |
| `123`, `1.5` | `"123"`, `"1.5"` |
| A `Stringable` object | its string |
| An array, `true` / `false`, any other object | `string` error |

### JSON

Only by `#[Cast('json')]`, on a `string` (or untyped / `mixed`) property:

| Input | Result |
|-------|--------|
| `['theme' => 'dark']` | `'{"theme":"dark"}'` |
| `'{"theme":"dark"}'` (a string) | kept as it is |
| `true`, `5` | `'true'`, `'5'` |

A string is not checked to be valid JSON — add a validation rule of your own if it must be.

### Array

Smart conversion logic:

1. **JSON**: Valid JSON array/object is decoded
2. **CSV**: Comma-separated string is split
3. **Single**: Non-empty string wrapped in array
4. **Empty**: Empty string becomes `[]`
5. **Traversable** (a value from context, say a generator): its items

| Input | Result |
|-------|--------|
| `'["a","b"]'` | `["a", "b"]` |
| `'a, b, c'` | `["a", "b", "c"]` |
| `'single'` | `["single"]` |
| `''` | `[]` |

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

A string of digits is a date when it spells one (`"20240115"`) and a Unix timestamp when PHP's parser cannot read it (`"1705276800"`). Digits the parser would take for a time of day — `"2024"` is 20:24 today — are a `date` error.

An impossible date or time — `2024-02-31`, `23:61` — is a `date` error (`date_format` with `#[DateFormat]`), not the 2 March or the next hour PHP's parser would roll it over into.

### Fixed format — `#[DateFormat]`

```php
use Solo\RequestHandler\Attributes\DateFormat;

#[DateFormat('Y-m-d')]
public DateTimeImmutable $birthDate;   // "1990-05-01" → 1990-05-01 00:00:00

#[DateFormat('Y-m-d H:i:s')]
public DateTime $eventTime;            // formats may contain ':'
```

A number is read in the format like a string — `20240115` for `#[DateFormat('Ymd')]`; declare `U` to accept a Unix timestamp. A format without time-of-day fields yields midnight. Other fields the format does not parse keep `createFromFormat()` semantics and come from the current date — `m-d` is in the current year; add `!` or `|` to the format to reset them yourself (`!Y-m` is the first of the month).

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

A caster with constructor dependencies comes from the PSR-11 container passed to `RequestHandler`, or from a registered instance:

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

`null` is never cast: a nullable property gets `null`, one with a default keeps its default, and any other property reports `required`.

Empty string `""` is handled per type:

| Type | `""` Result | Reason |
|------|-------------|--------|
| `int`, `float`, `bool` | no value | Not a number / boolean |
| date types, enums | no value | Not a date / case |
| `string` | `""` | Preserved as-is |
| `array` | `[]` | Empty collection |

"No value" means `null` on a nullable property, the default on one that has a default, and the `required` error otherwise. [`#[EmptyToNull]`](/features/field-attribute#emptytonull) extends that to `string` and `array` properties — on one property or a whole class.

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
