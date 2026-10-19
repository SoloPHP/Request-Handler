# Upgrading from 3.x to 4.0

4.0 makes the request contract stricter and easier to consume: values that cannot become the declared type are validation errors instead of silent coercions, errors and `schema()` are keyed by the input the client actually sends, and dates, nested objects and enums follow the property type.

Most applications need only the checklist below. The sections after it explain each change with before/after examples.

## Checklist

| Look for | Do |
|---|---|
| PHP 8.2 | Upgrade to PHP 8.3+ |
| `#[Cast('datetime…')]` | Remove it — the class follows the property type; move a format into `#[DateFormat('…')]` ([Dates](#dates-dateformat-replaces-the-datetime-cast-specs)) |
| `#[Cast('integer')]`, `'double'`, `'boolean'` | Use `'int'`, `'float'`, `'bool'` |
| A `string` property that receives arrays or objects (a JSON column) | Add `#[Cast('json')]` ([Casts](#casts-are-lossless)) |
| Tests or clients expecting `['rule' => 'scalar']` | Expect the type's rule: `integer`, `numeric`, `boolean` or `string` |
| Clients reading errors of `#[FromBody('path')]`, `#[FromRoute('key')]`, `#[FromContext('key')]` properties | Read them under the input key ([Error keys](#error-keys-follow-the-input)) |
| `$handler->schema(...)` consumers | Read fields by input key; `exclude` is gone ([Schema](#schema-v2)) |
| A property typed with a class that is not a Request subclass, enum or date — or with `object`, an intersection, a union of classes | Give it a `#[Caster]`, processor, `#[Generator]` or `#[FromContext]` ([Class types](#class-typed-properties)) |
| `#[FromContext]` values or generator results of a looser type — `1.5` or `true` for an `int`, `2` for a `bool`, `true` for a `string` | Pass the declared type — they throw `UnexpectedValueException` now ([Values the application supplies](#values-the-application-supplies)) |
| A `#[FromContext]` or generated value that may be `null` for a non-nullable property without a default | Make the property nullable or give it a default — `null` now throws `UnexpectedValueException` ([Values the application supplies](#values-the-application-supplies)) |
| `toArray()` overridden in a Request subclass | Declare `toArray(bool $onlyProvided = false): array` |
| `AuthorizationException` | Remove — it was never thrown |
| `Solo\RequestHandler\Cache\*`, `Casters\BuiltInCaster`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser`, `InputBags` used directly | Use the public API — these classes are now `@internal` |

Optional, but worth doing while you are there:

| Look for | Consider |
|---|---|
| A post-processor that only turns `""` into `null` | `#[EmptyToNull]` ([New features](#new-features)) |
| `$handler->register(X::class, $container->get(X::class))` | `new RequestHandler($validator, container: $container)` |
| PATCH endpoints reading the raw body to know what was sent | `$dto->provided()` / `$dto->toArray(onlyProvided: true)` |
| `#[PreProcess('trim')]` everywhere because auto-trim is off for one field | `#[NoTrim]` on that field, auto-trim back on |

---

## Requirements

- PHP **8.3** or newer (3.x: 8.2).
- `psr/container ^2.0` is now a direct dependency. It already came with `solophp/contracts`, so nothing new is installed.

## Casts are lossless

The type-driven casts used PHP's own coercion, so the declared type silently rewrote what the client sent. Now a value is converted only when nothing is lost; anything else is a validation error named like the validator rule for that type.

| Property | Input | 3.x | 4.0 |
|---|---|---|---|
| `int` | `"12abc"` | `12` | `['rule' => 'integer']` |
| `int` | `"1.5"` | `1` | `['rule' => 'integer']` |
| `int` | `[5]` | `['rule' => 'scalar']` | `['rule' => 'integer']` |
| `int` | `true` | `1` | `['rule' => 'integer']` |
| `float` | `"abc"` | `0.0` | `['rule' => 'numeric']` |
| `float` | `false` | `0.0` | `['rule' => 'numeric']` |
| `bool` | `"maybe"`, `2` | `true` | `['rule' => 'boolean']` |
| `bool` | `""` | `false` | no value — `null`, the default, or `required` |
| `string` | `true` | `"1"` | `['rule' => 'string']` |
| `string` | `["a"]` | `'["a"]'` | `['rule' => 'string']` |

The same casts apply to `#[FromContext]` values and generator results, where a failure throws `UnexpectedValueException` — see [Values the application supplies](#values-the-application-supplies).

The accepted forms are those of solophp/validator's `integer`, `numeric` and `boolean` rules — `"42"`, `"-5"`, `"9.99"`, `"1e3"`, `"true"`, `"off"`, `1`, `0` and so on still convert — except that a JSON `true` / `false` is never a number. A field whose `#[Validate]` already checks its type sees no difference — the validator reports first.

Watch for properties whose type contradicts their rule: an `int` property validated as `boolean` used to turn `"true"` into `0` without a word, and a JSON `true` into `1`; now both report `integer`. Fix the type (`bool`).

To store structured input in a string property — a JSON column — say so:

```php
// 3.x: arrays were JSON-encoded into any string property
public ?string $settings = null;

// 4.0
#[Cast('json')]
public ?string $settings = null;   // ['theme' => 'dark'] → '{"theme":"dark"}'
```

## Error keys follow the input

`ValidationException` keys were property names, so a field read from `display_name` reported its error under `name` — a key the client never sent. Errors are now keyed by the property's **input key**:

```php
#[FromBody('display_name')]
#[Validate('required|string')]
public string $name;

#[FromRoute('product_id')]
#[Validate('required|integer')]
public int $id;

// 3.x: ['name' => [...], 'id' => [...]]
// 4.0: ['display_name' => [...], 'product_id' => [...]]
```

Properties read by their own name — the default case — keep their keys. Nested objects and items build their paths from the parent's input key (`order.lines.0.quantity`). Rule strings and the validation payload still use property names (`same:password`, `{id}`).

## Dates: `#[DateFormat]` replaces the datetime cast specs

The class now comes from the property type, so the `datetime[:immutable][:format]` mini-language is gone. `#[Cast('datetime…')]` throws `ConfigurationException::castDateTimeRemoved` with a hint.

| 3.x | 4.0 |
|---|---|
| `#[Cast('datetime')] public DateTime $at;` | `public DateTime $at;` |
| `#[Cast('datetime:immutable')] public DateTimeImmutable $at;` | `public DateTimeImmutable $at;` |
| `#[Cast('datetime:Y-m-d')] public DateTime $day;` | `#[DateFormat('Y-m-d')] public DateTime $day;` |
| `#[Cast('datetime:immutable:Y-m-d')] public DateTimeImmutable $day;` | `#[DateFormat('Y-m-d')] public DateTimeImmutable $day;` |

A `DateTimeInterface` property now holds a `DateTimeImmutable` (3.x: `DateTime`). An untyped or `mixed` property with `#[DateFormat]` gets a `DateTimeImmutable`.

A string of digits PHP's parser reads as a time of day — `"2024"` → 20:24 today, `"1230"` → 12:30 — is now a `date` error. Digits that spell a date (`"20240115"`) and Unix timestamps (`"1705276800"`) are read as before.

`#[Cast]` takes `int`, `float`, `bool`, `string`, `array` and `json`; the aliases `integer`, `double` and `boolean` are gone.

## Class-typed properties

A property typed with a **Request subclass** is a nested object now: the input object runs the child's whole pipeline and errors come back under its path (`address.zip`). In 3.x the same property ended in a `TypeError` — a 500 the client could trigger.

```php
public AddressRequest $address;           // required: null → ['address' => [['rule' => 'required']]]
public ?AddressRequest $billing = null;
```

Any **other class type** needs something that produces it — `#[Caster]`, a pre/post-processor, `#[Generator]` or `#[FromContext]` (backed enums and DateTime classes are produced by their type). Otherwise building the metadata throws `ConfigurationException::unproducibleType` — at the first use of the class, not at the first request carrying the field. This includes pure (non-backed) enums, `object`, intersection types and unions of classes only. An object passed for such a property in the `handleArray()` data belongs in `context`, read with `#[FromContext]`.

A **union with a scalar member** and no `#[Cast]` (`int|float`, `int|string`) reached the property unconverted in 3.x: PHP coerced what it could and threw a `TypeError` for the rest. Now a value of a member type is kept, another one is converted to the first scalar member it fits without loss (`"5"` → `5` for `int|float`), and a value that fits none is a validation error — `numeric` for `int|float`, `string` for a union with `string`. An `iterable` property is cast like `array`.

## Schema v2

`schema()` now describes what the client sends:

- fields are keyed by **input key** (3.x: property name) and name their `property`;
- generated and `#[FromContext]` properties are left out;
- the `exclude` flag is gone — it describes output, not input;
- new keys: `default`, `format` (`#[DateFormat]`), `cases` (backed enums), `cast` (a `#[Cast]` the type does not show, such as `json`), `emptyToNull` / `noTrim` (the attributes), `fields` (nested DTO), `items` (`#[Items]`), with `['$ref' => Class]` for a class recurring on its own path, and `also` for further properties reading the same key;
- `requiredIf.field` is the referenced field's input key.

See [Schema Export](https://solophp.github.io/Request-Handler/features/schema).

## Values the application supplies

`#[FromContext]` values and generator results are the application's, not the client's:

- they go through the same lossless casts as client input, but a value that does not fit throws `UnexpectedValueException` instead of a validation error. 3.x coerced it: `1.5` or `true` for an `int`, `2` for a `bool`, `true` or an array for a `string` now throw — pass the declared type;
- `null` for a property that is neither nullable nor has a default throws `UnexpectedValueException` — 3.x left the property uninitialised, to fail later on first read;
- a `#[FromContext]` property typed with a Request subclass receives the object passed in `context` as it is — it is not built from input like a nested DTO;
- `#[EmptyToNull]` and `#[NoTrim]` on a class leave them alone.

## Request API

- `toArray()` takes `bool $onlyProvided = false`. A subclass overriding it must declare the parameter.
- `toArray()`, `has()`, `get()` and `group()` read the same metadata as the handler, so a misconfigured class throws `ConfigurationException` from them too.
- `AuthorizationException` is removed (the library never threw it).
- `FieldSource` is string-backed (`'body'`, `'query'`, `'route'`, `'context'`); its cases are unchanged.

## Internal classes

`Cache\*`, `Casters\BuiltInCaster`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser` and `InputBags` are marked `@internal` and may change in any release. The public API is `RequestHandler`, `Request`, the attributes, the contracts, `ProcessContext`, `FieldSource` and the exceptions.

---

## New features

None of these require changes; they are listed so the upgrade can drop workarounds.

- **`#[EmptyToNull]`** — on a property or a class: a blank string is no value (`null`, the default, or `required`), before pre-processing and validation. Replaces `''`→`null` post-processors, which also switched the field's cast off.
- **`#[NoTrim]`** — on a property or a class: auto-trim skips it.
- **`#[Cast('json')]`** — JSON-encodes structured input into a string property.
- **`provided()` / `isProvided()`** — which properties the request supplied, an explicit `null` included; `toArray(onlyProvided: true)` is the change set of a PATCH, nested DTOs and items included.
- **PSR-11 container** — `new RequestHandler($validator, container: $container)`: processors, casters and generators resolve to a registered instance, else the container's entry, else a no-argument instance.
- **Nested DTOs by type** and **schema v2**, described above.
