# Solo Request Handler

Type-safe Request DTOs for PHP 8.2+ with focused per-property attributes, declarative validation, type casting, and full IDE support.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Per-property source binding** — default source comes from the handle method (`handleBody` → Body, `handleQuery` → Query); `#[FromRoute]` and `#[FromContext]` override per property. No implicit merging, no surprises.
- **Attribute-composed DTOs** — each concern is its own focused attribute: `#[Validate]`, `#[Cast]`, `#[PostProcess]`, `#[Generator]`, `#[Items]`, …
- **Convention over configuration** — every public non-static property is managed; attributes only add behaviour
- **Automatic type casting** — driven by the property's declared type; built-ins for int, float, bool, string, array, DateTime + custom casters
- **Validation rules** — passed verbatim to your `ValidatorInterface`; route and context values land in the validation payload as regular fields, so cross-field rules (`exists:…,scope_col,{field}`) work without special syntax
- **Generators** — auto-generate UUIDs, sequences, custom values via `#[Generator]`
- **Pre/post processing** — `#[PreProcess]` / `#[PostProcess]` for transformation
- **Nested items** — `#[Items]` validates arrays of nested Request DTOs with dot-notation error paths
- **Fail-fast** — every misconfiguration throws `ConfigurationException` at metadata-build time, not at first request

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

    #[Validate('required|numeric|min:0')]
    public float $price;

    #[Validate('nullable|integer|min:0')]
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
| `#[Cast('int'\|'float'\|'datetime:Y-m-d'\|...)]` | Explicit built-in cast |
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

- PHP 8.2+
- PSR-7 HTTP Message implementation
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

## License

MIT License. See [LICENSE](LICENSE) for details.
