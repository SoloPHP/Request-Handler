# Solo Request Handler

Type-safe Request DTOs for PHP 8.3+ with focused per-property attributes, declarative validation, type casting, and full IDE support.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Per-property source binding** — default source comes from the handle method (`handleBody` → Body, `handleQuery` → Query); `#[FromRoute]` and `#[FromContext]` override per property. No implicit merging, no surprises.
- **Attribute-composed DTOs** — each concern is its own focused attribute: `#[Validate]`, `#[DateFormat]`, `#[PostProcess]`, `#[Generator]`, `#[Items]`, …
- **Convention over configuration** — every public non-static property is managed; attributes only add behaviour
- **Automatic type casting** — driven by the property's declared type: int, float, bool, string, array, `DateTime` / `DateTimeImmutable`, backed enums + custom casters. Arrays sent for int/float/bool fields, unparseable dates and unknown enum values are validation errors rather than `1`, `null` or an uninitialised property
- **Validation rules** — passed verbatim to your `ValidatorInterface`; every field present in the request — route and context values included, with or without rules of their own — lands in the validation payload, so cross-field rules (`exists:…,scope_col,{field}`) work without special syntax
- **All errors at once** — validator errors, cast failures and nested-item errors come back in one `ValidationException`; generators and post-processors run only for valid input
- **Generators** — auto-generate UUIDs, sequences, custom values via `#[Generator]`
- **Pre/post processing** — `#[PreProcess]` / `#[PostProcess]` for transformation
- **Nested items** — `#[Items]` validates arrays of nested Request DTOs with dot-notation error paths
- **Fail-fast** — misconfigurations throw `ConfigurationException` at metadata-build time (nested item classes included), not on the first request that happens to exercise them
- **Schema export** — `schema()` emits JSON-serializable per-field metadata (type, nullability, requiredness incl. conditional `required_if`, normalized rules) so the frontend can treat the backend as the single source of truth

## Installation

```bash
composer require solophp/request-handler
```

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
| `#[Cast('int'\|'float'\|'bool'\|'string'\|'array')]` | Explicit scalar cast (union / `mixed` properties) |
| `#[DateFormat('Y-m-d')]` | Fixed input format for a date property — the class follows the type |
| `#[Caster(MoneyCaster::class)]` | Custom `CasterInterface` implementation |
| `#[PreProcess(MyProc::class)]` | Run handler BEFORE validation |
| `#[PostProcess(MyProc::class, config: [...])]` | Run handler AFTER validation, with optional config |
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
//     'type' => 'int', 'nullable' => false, 'required' => true,
//     'requiredIf' => null, 'hasDefault' => false, 'exclude' => false,
//     'source' => 'route',
//     'rules' => [['name' => 'required', 'args' => []], ['name' => 'integer', 'args' => []]],
//   ],
//   'name' => [
//     'type' => 'string', 'nullable' => false, 'required' => true,
//     'requiredIf' => null, 'hasDefault' => false, 'exclude' => false, 'source' => null,
//     'rules' => [['name' => 'required', 'args' => []], ['name' => 'string', 'args' => []], ['name' => 'max', 'args' => ['255']]],
//   ],
//   'slug' => [
//     'type' => 'string', 'nullable' => true, 'required' => false,
//     'requiredIf' => ['field' => 'status', 'value' => 'published'],
//     'hasDefault' => true, 'exclude' => false, 'source' => null,
//     'rules' => [/* … incl. ['name' => 'required_if', 'args' => ['status', 'published']] */],
//   ],
// ]

echo json_encode($schema); // round-trips losslessly — scalars and arrays only
```

Every managed property is emitted (excluded/`#[FromRoute]`/`#[FromContext]` fields included) — `exclude` and `source` are reported as flags so the consumer decides what to render. Notes:

- `required` reflects the **literal** `required` rule only. Conditional requiredness lives in `requiredIf` (the first well-formed `required_if`, as `{field, value}`), and the rule itself is still present in `rules`.
- `source` is `'body' | 'query' | 'route' | 'context'`, or `null` when the field uses the handle method's default bag.
- `rules` is the full normalized rule list, split with the same semantics the handler uses internally: `name` is the part before the first `:`, `args` is the comma-split remainder.

## Documentation

**[Full Documentation](https://solophp.github.io/Request-Handler/)**

- [Installation](https://solophp.github.io/Request-Handler/guide/installation)
- [Quick Start](https://solophp.github.io/Request-Handler/guide/quick-start)
- [Configuration](https://solophp.github.io/Request-Handler/guide/configuration)
- [Attributes](https://solophp.github.io/Request-Handler/features/field-attribute)
- [Type Casting](https://solophp.github.io/Request-Handler/features/type-casting)
- [Processors](https://solophp.github.io/Request-Handler/features/processors)
- [Generators](https://solophp.github.io/Request-Handler/features/generators)
- [Nested Items](https://solophp.github.io/Request-Handler/features/nested-items)
- [Validation](https://solophp.github.io/Request-Handler/features/validation)
- [API Reference](https://solophp.github.io/Request-Handler/api/request-class)

## Requirements

- PHP 8.3+
- PSR-7 HTTP Message implementation
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

## Public API

`RequestHandler`, `Request`, the attributes, the contracts (`CasterInterface`, `GeneratorInterface`, `ProcessorInterface`), `ProcessContext`, `FieldSource` and the exceptions are the public API and follow semantic versioning. Classes marked `@internal` — metadata (`Cache\*`), `Casters\BuiltInCaster`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser`, `InputBags` — may change in any release.

## License

MIT License. See [LICENSE](LICENSE) for details.
