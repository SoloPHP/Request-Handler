# Upgrading from 3.x to 4.0

4.0 makes the request contract stricter and easier to consume: values that cannot become the declared type are validation errors instead of silent coercions, errors and `schema()` are keyed by the input the client actually sends, and dates, nested objects and enums follow the property type.

The checklist covers upgrades from 3.x. The processing-stage changes also apply to applications already using a pre-release of the `4.0` branch.

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
| A property typed with a class that is not a Request subclass, enum or date — or with `object`, an intersection, a union of classes | Give it a `#[Caster]`, `#[Generator]` or `#[FromContext]` ([Class types](#class-typed-properties)) |
| `#[PreProcess]` / `ProcessorInterface` | Split preparation, conversion and transformation into `#[Normalize]`, `#[Caster]` and `#[PostProcess]` ([Processing stages](#processing-stages)): `process()` becomes `normalize()`, `cast()` or `postProcess()`; a normalizer also receives an explicit `null` and `""` |
| Function/static-method strings in processor attributes | Extract a class implementing the matching interface; `PostProcess(handler: ...)` becomes `PostProcess(class: ...)` |
| Existing custom casters | Add `ProcessContext $context` to `cast()` and use field-local errors |
| `#[FromContext]` values or generator results of a looser type — `1.5` or `true` for an `int`, `2` for a `bool`, `true` for a `string` | Pass the declared type — they throw `UnexpectedValueException` now ([Values the application supplies](#values-the-application-supplies)) |
| `#[Items]` on a `#[FromContext]` property fed with arrays | Pass the built DTOs — the arrays throw `UnexpectedValueException` now |
| A caster/post-processor returning a loosely compatible value or null for a non-nullable property | Return the declared type; defaults no longer rescue a null result ([What a handler returns](#what-a-handler-returns)) |
| A misspelled or misplaced attribute from `Solo\RequestHandler\Attributes\` — say `Attributes\FromContext` for `Attributes\Source\FromContext` | Fix the name — it throws `ConfigurationException::unknownAttribute` instead of being ignored |
| Tests expecting the `invalidCaster` or `invalidGenerator` message | Expect `invalidHandler`'s: `has invalid #[Caster(…)]: class must implement …`; an abstract generator is now resolved on first use, like any handler |
| `ValidationException` thrown by a normalizer, caster, post-processor or generator | Use `FieldValidationException` in normalization/casting; move rejection out of post-processing/generation |
| A `#[FromContext]` or generated value that may be `null` for a non-nullable property without a default | Make the property nullable or give it a default — `null` now throws `UnexpectedValueException` ([Values the application supplies](#values-the-application-supplies)) |
| A `#[FromContext]` or generated value that may be `""` for a number, date or object | Pass `null` — `""` throws `UnexpectedValueException` now ([Values the application supplies](#values-the-application-supplies)) |
| `toArray()` overridden in a Request subclass | Declare `toArray(bool $onlyProvided = false): array` |
| `AuthorizationException` | Remove — it was never thrown |
| `Solo\RequestHandler\Cache\*`, `Casters\BuiltInCaster`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser`, `InputBags` used directly | Use the public API — these classes are now `@internal` |

Optional, but worth doing while you are there:

| Look for | Consider |
|---|---|
| A post-processor that only turns `""` into `null` | `#[EmptyToNull]` ([New features](#new-features)) |
| `$handler->register(X::class, $container->get(X::class))` | `new RequestHandler($validator, container: $container)` |
| PATCH endpoints reading the raw body to know what was sent | `$dto->provided()` / `$dto->toArray(onlyProvided: true)` |
| Manual trim handlers everywhere because auto-trim is off for one field | `#[NoTrim]` on that field, auto-trim back on |

---

## Requirements

- PHP **8.3** or newer (3.x: 8.2).
- `psr/container ^2.0` is now a direct dependency. It already came with `solophp/contracts`, so nothing new is installed.

## Processing stages

This is a breaking change from both 3.x and earlier pre-releases of 4.0. The stages now have separate contracts:

| Responsibility | Attribute | Contract |
|---|---|---|
| Prepare raw input before validation | `#[Normalize(Class::class, config: [])]` | `NormalizerInterface::normalize(mixed $value, ProcessContext $context): mixed` |
| Decode input into the property type | `#[Caster(Class::class, config: [])]` | `CasterInterface::cast(mixed $value, ProcessContext $context): mixed` |
| Transform an already decoded value | `#[PostProcess(Class::class, config: [])]` | `PostProcessorInterface::postProcess(mixed $value, ProcessContext $context): mixed` |

`PreProcess` and `ProcessorInterface` are removed. Using the old attribute fails with `ConfigurationException::unknownAttribute`, like any attribute the library does not know. Functions, static-method names and using a caster class as a processor are no longer supported. All three attributes take interface classes and optional configuration; all three receive `ProcessContext` with `config`, `route` and `context`. The named parameter of `PostProcess` changes from `handler` to `class`. Each contract names its stage in its method — `normalize()`, `cast()`, `postProcess()` — so a 3.x class with `process()` fails to load until you choose its stage.

### Choose the stage by responsibility

Do not mechanically rename every `PreProcess`:

- trimming, case normalization or decoding a JSON string before `#[Items]` → `NormalizerInterface`;
- creating `Money`, UUIDs or another value object → `CasterInterface`;
- clamping a decoded integer or hashing a validated string → `PostProcessorInterface`;
- rejecting an invalid input → the validator or `FieldValidationException` from a normalizer/caster.

A normalizer runs for every supplied value, an explicit `null` and `""` included, where a 3.x pre-processor never saw them: return values it does not handle unchanged.

Before:

```php
#[PreProcess('strtolower')]
public string $email;

#[PostProcess('clamp')]
public int $limit = 20;
```

After:

```php
use Solo\RequestHandler\Attributes\{Normalize, PostProcess};
use Solo\RequestHandler\Contracts\{NormalizerInterface, PostProcessorInterface};
use Solo\RequestHandler\ProcessContext;

final class Lowercase implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        return is_string($value) ? strtolower($value) : $value;
    }
}

/** @implements PostProcessorInterface<int> */
final class ClampLimit implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): int
    {
        return max(1, min($context->config['max'], $value));
    }
}

// In the Request subclass:
#[Normalize(Lowercase::class)]
public string $email;

#[PostProcess(ClampLimit::class, config: ['max' => 100])]
public int $limit = 20;
```

The interface parameters remain `mixed`; use `@implements PostProcessorInterface<int>` to express the decoded type to static analysis. Normalizers always inspect raw input explicitly. They run for every present field, including null and empty strings; they are never skipped because of an inferred callback signature.

### Update custom casters and rejection

Add the context argument to every caster, even if unused. Replace field-keyed aggregate exceptions with a field-local exception:

```php
use Solo\RequestHandler\Contracts\CasterInterface;
use Solo\RequestHandler\Exceptions\FieldValidationException;
use Solo\RequestHandler\ProcessContext;

/** @implements CasterInterface<Money> */
final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): Money
    {
        if (!is_string($value)) {
            throw new FieldValidationException('money');
        }
        try {
            return Money::fromDecimal($value);
        } catch (InvalidMoney $e) {
            throw new FieldValidationException('money', params: ['decimal'], previous: $e);
        }
    }
}
```

Here `Money` and `InvalidMoney` are application classes. The handler supplies the actual input path (`lines.2.price`) and aggregates rejections with validator and nested errors in the public `ValidationException`.

Throwing `ValidationException` directly from a normalizer/caster is a `LogicException`. Throwing either validation exception from a post-processor or generator is also a `LogicException`: move input rejection earlier. The original exception is retained as `getPrevious()`. A field rejection on `#[FromContext]` becomes `UnexpectedValueException`, since the value came from the application.

### Update assumptions about execution order

Resolution is **auto-trim → normalizer → EmptyToNull → raw validation → empty/default policy → one custom or built-in decoder**, recursively for all input nodes. Raw validation sees normalized input, before casting. A field with a raw error does not run its caster; a normalizer failure omits the field from the validation payload. Other fields still resolve, so custom casters can now run even when a sibling is invalid. Keep normalizers and casters pure.

After the entire tree is valid, each node finalizes bottom-up: **children → local post-processors → local generators → DTO population**. A parent's post-processor receives complete child DTOs, including generated fields. A child generator can therefore run before a parent's post-processor. No post-processor or generator runs for a tree with input errors. Application errors during finalization do not roll back earlier work.

A post-processor no longer disables built-in casting or returns raw data for a second nested pipeline. Move JSON-to-array preparation to a normalizer before `#[Items]`.

### Check absent, null and blank inputs

- An absent key runs none of the three field handlers, keeps its default and remains absent in `provided()`.
- A present null/blank runs the normalizer. It can replace that value; `EmptyToNull` runs afterwards.
- Null after normalization bypasses the caster: nullable properties get null, otherwise their default or a `required` error. A non-null default selected for a present field reaches the post-processor; a null value does not.
- For a custom caster, client `""` is passed through when the property accepts string/mixed, becomes `[]` for array/iterable, and otherwise means no value. The caster is bypassed for the latter two cases. Context `""` remains an actual value.
- Null returned by a caster/post-processor is accepted only by a nullable property; it never falls back to the default.

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
| `float` | `"1e400"` | `INF` | `['rule' => 'numeric']` |
| `bool` | `"maybe"`, `2` | `true` | `['rule' => 'boolean']` |
| `bool` | `""` | `false` | no value — `null`, the default, or `required` |
| `string` | `true` | `"1"` | `['rule' => 'string']` |
| `string` | `["a"]` | `'["a"]'` | `['rule' => 'string']` |

The same casts apply to `#[FromContext]` values and generator results, where a failure throws `UnexpectedValueException` — see [Values the application supplies](#values-the-application-supplies).

Post-processors now receive the property's decoded type: `"5000"` for an `int` property becomes `5000` before processing. There is no callback-signature coercion or automatic skip. `"abc"` produces an `integer` error before post-processing. See [Processing stages](#processing-stages).

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

The class now comes from the property type, so the `datetime[:immutable][:format]` mini-language is gone: `#[Cast('datetime…')]` fails like any cast that is not built in (`ConfigurationException::castExpectsBuiltInType` or `unknownCastType`).

| 3.x | 4.0 |
|---|---|
| `#[Cast('datetime')] public DateTime $at;` | `public DateTime $at;` |
| `#[Cast('datetime:immutable')] public DateTimeImmutable $at;` | `public DateTimeImmutable $at;` |
| `#[Cast('datetime:Y-m-d')] public DateTime $day;` | `#[DateFormat('Y-m-d')] public DateTime $day;` |
| `#[Cast('datetime:immutable:Y-m-d')] public DateTimeImmutable $day;` | `#[DateFormat('Y-m-d')] public DateTimeImmutable $day;` |

A `DateTimeInterface` property now holds a `DateTimeImmutable` (3.x: `DateTime`). An untyped or `mixed` property with `#[DateFormat]` gets a `DateTimeImmutable`. With a format, a JSON number is read in it like a string (`20240115` for `Ymd`) instead of as a Unix timestamp — declare `U` to accept timestamps.

An impossible date or time — `"2024-02-31"`, `"23:61"` — is now a `date` / `date_format` error; 3.x stored what PHP's parser rolled it over into (2 March, the next hour).

A string of digits PHP's parser reads as a time of day — `"2024"` → 20:24 today, `"1230"` → 12:30 — is now a `date` error. Digits that spell a date (`"20240115"`) and Unix timestamps (`"1705276800"`) are read as before.

`#[Cast]` takes `int`, `float`, `bool`, `string`, `array` and `json`; the aliases `integer`, `double` and `boolean` are gone.

## Class-typed properties

A property typed with a **Request subclass** is a nested object now: the input object runs the child's whole pipeline and errors come back under its path (`address.zip`). In 3.x the same property ended in a `TypeError` — a 500 the client could trigger.

```php
public AddressRequest $address;           // required: null → ['address' => [['rule' => 'required']]]
public ?AddressRequest $billing = null;
```

Any **other class type** needs something that produces it — `#[Caster]`, `#[Generator]` or `#[FromContext]` (backed enums and DateTime classes are produced by their type). Otherwise building the metadata throws `ConfigurationException::unproducibleType` — at the first use of the class, not at the first request carrying the field. This includes pure (non-backed) enums, `object`, intersection types and unions of classes only. An object passed for such a property in the `handleArray()` data belongs in `context`, read with `#[FromContext]`.

A normalizer or post-processor no longer counts as a producer for a class property. Move parsing such as `parseMoney(string $value): Money` into a `CasterInterface` implementation. Its input parameter is `mixed`: inspect the input explicitly, return `Money`, or throw `FieldValidationException`.

A **union with a scalar member** and no `#[Cast]` (`int|float`, `int|string`) reached the property unconverted in 3.x: PHP coerced what it could and threw a `TypeError` for the rest. Now a value of a member type is kept, another one is converted to the first scalar member it fits without loss (`"5"` → `5` for `int|float`), and a value that fits none is a validation error — `numeric` for `int|float`, `string` for a union with `string`. An `iterable` property keeps an array or a Traversable as it is and reads anything else like `array`.

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
- `""` is a value, not the absence of one: for a type that cannot hold a string — a number, a date, an object — it throws `UnexpectedValueException` (a cleared client field, by contrast, is no value);
- a `#[FromContext]` property typed with a Request subclass receives the object passed in `context` as it is — it is not built from input like a nested DTO;
- `#[Items]` on a `#[FromContext]` list declares the class of the DTOs the application passes: they are checked, not built from arrays as in 3.x;
- auto-trim leaves them as passed (3.x trimmed context strings), and a class-level `#[EmptyToNull]` skips them.

## What a handler returns

A custom caster or post-processor must return a value compatible with the declared property type. Results are checked immediately and never decoded again:

- `true` and `"5"` cannot become an `int`; incompatible results throw `UnexpectedValueException` naming the handler;
- `null` is valid only for a nullable property, even when a non-nullable property has a default;
- normal PHP widening from int to float remains compatible;
- an `#[Items]` post-processor must return DTOs of the item class, not raw arrays; returned children are not revalidated.

Defaults apply to absent/empty **input**, not incorrect handler results. To reject client input, normalizers and casters throw `FieldValidationException`; post-processors cannot reject input.

## Request API

- `toArray()` takes `bool $onlyProvided = false`. A subclass overriding it must declare the parameter.
- `toArray()`, `has()`, `get()` and `group()` read the same metadata as the handler, so a misconfigured class throws `ConfigurationException` from them too.
- `AuthorizationException` is removed (the library never threw it).
- `FieldSource` is string-backed (`'body'`, `'query'`, `'route'`, `'context'`); its cases are unchanged.

## Internal classes

`Cache\*`, `Casters\BuiltInCaster`, `Grouping\GroupBuilder`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser` and `InputBags` are marked `@internal` and may change in any release. The public API is `RequestHandler`, `Request`, the attributes, the contracts, `ProcessContext`, `FieldSource` and the exceptions.

---

## New features

None of these require changes; they are listed so the upgrade can drop workarounds.

- **`#[EmptyToNull]`** — on a property or a class: a blank string is no value (`null`, the default, or `required`), after normalization and before validation. Replaces `''`→`null` pre-processors.
- **`#[NoTrim]`** — on a property or a class: auto-trim skips it.
- **`#[Cast('json')]`** — JSON-encodes structured input into a string property.
- **`provided()` / `isProvided()`** — which properties the request supplied, an explicit `null` included; `toArray(onlyProvided: true)` is the change set of a PATCH, nested DTOs and items included.
- **PSR-11 container** — `new RequestHandler($validator, container: $container)`: normalizers, casters, post-processors and generators resolve to a registered instance, else the container's entry, else a no-argument instance.
- **Nested DTOs by type** and **schema v2**, described above.
