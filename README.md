# Solo Request Handler

Type-safe Request DTOs for PHP 8.3+ with focused per-property attributes, declarative validation, type casting, and full IDE support.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Per-property source binding** — default source comes from the handle method (`handleBody` → Body, `handleQuery` → Query); `#[FromRoute]` and `#[FromContext]` override per property. No implicit merging, no surprises.
- **Attribute-composed DTOs** — each concern is its own focused attribute: `#[Validate]`, `#[DateFormat]`, `#[PostProcess]`, `#[Generator]`, `#[Items]`, …
- **Convention over configuration** — every public non-static property is managed; attributes only add behaviour
- **Automatic type casting** — driven by the property's declared type: int, float, bool, string, array, `DateTime` / `DateTimeImmutable`, backed enums + custom casters. Casts are lossless: `"12abc"` for an int, `"maybe"` for a bool, an array for a string, an unparseable date or an unknown enum value is a validation error, never a silently coerced value
- **Validation rules** — passed verbatim to your `ValidatorInterface`; every field present in the request — route and context values included, with or without rules of their own — lands in the validation payload, so cross-field rules (`exists:…,scope_col,{field}`) work without special syntax
- **All errors at once** — validator errors, cast failures and nested errors come back in one `ValidationException`, keyed by the input the client sent (`display_name`, `customer.id`, `lines.0.qty`); generators and post-processors run only for valid input
- **PATCH-ready** — `provided()` / `isProvided()` tell a field the client sent (even as `null`) from one left at its default; `toArray(onlyProvided: true)` is the change set
- **Generators** — auto-generate UUIDs, sequences, custom values via `#[Generator]`
- **PSR-11 ready** — normalizers, casters, post-processors and generators with dependencies come from your container: `new RequestHandler($validator, container: $container)`
- **Explicit processing stages** — `#[Normalize]` prepares raw input, `#[Caster]` creates its type, `#[PostProcess]` transforms decoded values after the whole tree is valid
- **Nested DTOs** — a property typed with a Request subclass is a nested object, `#[Items]` makes a list of them; errors come back as `address.city`, `lines.0.qty`
- **Fail-fast** — misconfigurations throw `ConfigurationException` at metadata-build time (nested item classes included), not on the first request that happens to exercise them
- **Schema export** — `schema()` emits the JSON-serializable input contract: per field type, nullability, requiredness incl. conditional `required_if`, normalized rules, default, date format, enum cases and nested schemas — the frontend's single source of truth

## Installation

```bash
composer require solophp/request-handler
```

Upgrading from 3.x? See [UPGRADE.md](UPGRADE.md).

## Quick Example

```php
use Solo\RequestHandler\Attributes\{Validate, Generator, Items, Exclude};
use Solo\RequestHandler\Attributes\Source\{FromRoute, FromContext};
use Solo\RequestHandler\Request;

final class UpdateProductRequest extends Request
{
    // PUT /products/{id}
    #[FromRoute]
    #[Validate('required|integer|exists:products,id')]
    public int $id;

    // default body — no source attribute needed
    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('required|numeric|min_value:0')]
    public float $price;

    #[Validate('integer|min_value:0')]
    public int $stock = 0;

    #[FromContext('tenantId')]
    #[Validate('required|integer')]
    public int $tenantId;

    #[FromContext('authUserId')]
    #[Validate('required|integer')]
    public int $updatedBy;

    #[Validate('required|string|min:8')]
    #[Exclude]              // validated/processed but hidden from toArray()
    public string $newPassword;
}

// Controller
$dto = $requestHandler->handleBody(
    UpdateProductRequest::class,
    $request,
    route:   ['id' => $productId],
    context: ['tenantId' => $tenant->id(), 'authUserId' => $auth->id()],
);

$dto->id;          // int — pulled from URL path
$dto->name;        // string — from body
$dto->tenantId;    // int — from application context
$dto->updatedBy;   // int — from application context
$dto->toArray();   // ['id'=>…, 'name'=>…, 'price'=>…, 'stock'=>…, 'tenantId'=>…, 'updatedBy'=>…] — no newPassword
```

## Attribute family

| Attribute | Purpose |
|---|---|
| `#[Validate('rules')]` | Validation rules (passed to your `ValidatorInterface`) |
| `#[FromRoute(?string $key = null)]` | Read from the `$route` argument (URL path params) |
| `#[FromContext(string $key)]` | Read from the `$context` argument (auth user, tenant, …) |
| `#[FromBody(string $path)]` | Read from a nested body path (`customer.id`); flat keys may also be remapped this way |
| `#[Cast('int'\|'float'\|'bool'\|'string'\|'array'\|'json')]` | Explicit cast (union / `mixed` properties); `json` encodes into a string |
| `#[DateFormat('Y-m-d')]` | Fixed input format for a date property — the class follows the type |
| `#[Caster(MoneyCaster::class)]` | Custom `CasterInterface` implementation |
| `#[EmptyToNull]` | A blank string is no value — `null`, the default or `required` (property or class) |
| `#[NoTrim]` | Auto-trim skips it — the string is kept as sent (property or class) |
| `#[Normalize(MyNormalizer::class, config: [...])]` | Normalize present input before validation |
| `#[PostProcess(MyProc::class, config: [...])]` | Transform decoded values after the whole input tree is valid |
| `#[Generator(UuidGenerator::class, options: [...])]` | Value is generated, not read from any source |
| `#[Items(OrderItemRequest::class)]` | Property is an array of nested DTOs |
| `#[Group('criteria', mapTo: 'positions.id')]` | Group membership + remap for `Request::group()` |
| `#[Exclude]` | `Request::toArray()` skips this property (still validated & processed) |
| `#[Ignore]` | Property is invisible to the handler AND to `toArray()`/`has()`/`get()`/`group()` |

Properties without a source attribute read from the default-source bag (Body for `handleBody`/`handleArray`, Query for `handleQuery`) by name. For values that don't fit the four bags (Body, Query, Route, Context) — request headers, cookies, file uploads — extract them in the controller and pass them via `route: [...]` or `context: [...]`.

To opt a public property out of input processing entirely, mark it with `#[Ignore]` — the handler skips it and `toArray()`/`has()`/`get()`/`group()` will not see it either. Or change its visibility to `protected`/`private` — the handler only touches public properties.

## Schema introspection

`schema()` reflects a request class into a JSON-serializable description of its input contract — without touching any HTTP input. Hand it to the frontend so validation rules live in exactly one place.

```php
$schema = $requestHandler->schema(UpdateProductRequest::class);

// [
//   'id' => [
//     'property' => 'id', 'source' => 'route', 'type' => 'int', 'nullable' => false,
//     'required' => true, 'requiredIf' => null, 'hasDefault' => false, 'default' => null,
//     'rules' => [['name' => 'required', 'args' => []], ['name' => 'integer', 'args' => []]],
//   ],
//   'name' => [
//     'property' => 'name', 'source' => null, 'type' => 'string', 'nullable' => false,
//     'required' => true, 'requiredIf' => null, 'hasDefault' => false, 'default' => null,
//     'rules' => [['name' => 'required', 'args' => []], ['name' => 'string', 'args' => []], ['name' => 'max', 'args' => ['255']]],
//   ],
//   …
// ]

echo json_encode($schema); // round-trips losslessly — scalars and arrays only
```

Fields are keyed by their **input key** — the key the client sends and errors are reported under. Generated and `#[FromContext]` properties are left out (the client never sends them). Dates carry their `format`, backed enums their `cases`, nested DTOs and `#[Items]` their own schema (`fields` / `items`); a `#[Cast]` the type does not show is reported as `cast` (`json`), and `#[EmptyToNull]` / `#[NoTrim]` as flags. `required` reflects the literal `required` rule; conditional requiredness lives in `requiredIf` (`{field, value}`, with `field` as an input key). See [Schema Export](https://solophp.github.io/Request-Handler/features/schema).

## Documentation

**[Full Documentation](https://solophp.github.io/Request-Handler/)**

- [Installation](https://solophp.github.io/Request-Handler/guide/installation)
- [Quick Start](https://solophp.github.io/Request-Handler/guide/quick-start)
- [Configuration](https://solophp.github.io/Request-Handler/guide/configuration)
- [Attributes](https://solophp.github.io/Request-Handler/features/field-attribute)
- [Type Casting](https://solophp.github.io/Request-Handler/features/type-casting)
- [Processing stages](https://solophp.github.io/Request-Handler/features/processors)
- [Generators](https://solophp.github.io/Request-Handler/features/generators)
- [Nested Objects & Items](https://solophp.github.io/Request-Handler/features/nested-items)
- [Validation](https://solophp.github.io/Request-Handler/features/validation)
- [API Reference](https://solophp.github.io/Request-Handler/api/request-class)

## Requirements

- PHP 8.3+
- PSR-7 HTTP Message implementation
- PSR-11 container (optional) — for normalizers, casters, post-processors and generators with constructor dependencies
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

## Public API

`RequestHandler`, `Request`, the attributes, the contracts (`NormalizerInterface`, `CasterInterface`, `PostProcessorInterface`, `GeneratorInterface`), `ProcessContext`, `FieldSource` and the exceptions are the public API and follow semantic versioning. Classes marked `@internal` — metadata (`Cache\*`), `Casters\BuiltInCaster`, `Grouping\GroupBuilder`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser`, `InputBags` — may change in any release.

## License

MIT License. See [LICENSE](LICENSE) for details.
