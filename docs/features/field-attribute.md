# Attributes

The package uses a family of small, focused attributes. Each one carries a single concern; combine as many as you need on one property.

There are three groups:

1. **Source attributes** (under `Solo\RequestHandler\Attributes\Source\*`) — declare WHERE the value comes from. Optional; without one the property reads from the default-source bag.
2. **Pipeline attributes** (under `Solo\RequestHandler\Attributes\*`) — describe what to do with the value (validate, cast, transform, generate, group, exclude).
3. **No marker required** — every public non-static property is managed; protected/private properties are ignored.

## Overview

### Source

| Attribute | Source bag | Key default | Notes |
|---|---|---|---|
| `#[FromRoute(?string $key = null)]` | `$route` argument | property name | flat key |
| `#[FromContext(string $key)]` | `$context` argument | required | flat key |
| `#[FromBody(string $path)]` | body bag | required | dot-path; single-segment values act as a flat key remap |

Without any source attribute, the property uses the default source dictated by the `handle*` method invoked (`handleBody` / `handleArray` → Body, `handleQuery` → Query). Declaring more than one source attribute on the same property throws `ConfigurationException::multipleSources` at metadata build time.

For values that don't fit the four bags (Body, Query, Route, Context) — request headers, cookies, file uploads — extract them in your controller and pass them via `route: [...]` or `context: [...]`.

### Pipeline

| Attribute | Purpose |
|---|---|
| `#[Validate('rules')]` | Validation rules passed to your `ValidatorInterface` |
| `#[Cast('int'\|'float'\|'bool'\|'string'\|'array')]` | Explicit scalar cast for union / `mixed` properties |
| `#[DateFormat('Y-m-d')]` | Fixed input format for a date property (class from the type) |
| `#[Caster(MoneyCaster::class)]` | Custom `CasterInterface` implementation |
| `#[PreProcess(handler)]` | Run handler BEFORE validation |
| `#[PostProcess(handler, config: [...])]` | Run handler AFTER validation, with optional config |
| `#[Generator(GenClass::class, options: [...])]` | Value is generated, not read from any source |
| `#[Items(NestedRequest::class)]` | Property is an array of nested DTOs |
| `#[Group('name', mapTo: '...')]` | Group membership + remap for `Request::group()` |
| `#[Exclude]` | `Request::toArray()` skips this property |

A bare `public string $name;` (no attributes) is read from the default-source bag by name and assigned with type-driven cast — no validation, no processors.

---

## Source attributes

### `#[FromRoute(?string $key = null)]`

Read from the `$route` argument passed to `handle*()` — typically URL path parameters:

```php
// PUT /products/{id}
#[FromRoute]                                            // key = 'id'
#[Validate('required|integer|exists:products,id')]
public int $id;

#[FromRoute('product_id')]                              // explicit key
public int $productId;
```

### `#[FromContext(string $key)]`

Read from the `$context` argument — for application-supplied values that aren't part of the HTTP request itself (auth user, tenant, feature flags):

```php
#[FromContext('authUserId')]
#[Validate('required|integer')]
public int $createdBy;
```

### `#[FromBody(string $path)]`

Read from a nested key inside the body via a dot-path:

```php
// Body: {"customer": {"id": 42, "name": "Alice"}}
#[FromBody('customer.id')]
#[Validate('required|integer')]
public int $customerId;
```

A single-segment path acts as a flat key remap:

```php
// Body: {"display_name": "Alice"}
#[FromBody('display_name')]
public string $name;
```

Malformed paths (leading/trailing dot, empty segment) throw `InvalidArgumentException` at construction. If the path resolves to an array but the property is a scalar (string included), a structured `ValidationException` is raised (`['field' => [['rule' => 'scalar']]]`) — unless a pre/post-processor or custom caster converts the array; when the field has rules, the validator's error (e.g. `integer`) is reported instead.

---

## Pipeline attributes

### `#[Validate('rules')]`

Rules are passed verbatim to your `ValidatorInterface::validate()`. The handler does not transform rule strings — cross-field references (e.g. `unique:users,email,{id}`) are the validator's concern, and a typical implementation resolves `{id}` against the validation payload, which contains every DTO field (including those marked `#[FromRoute]` / `#[FromContext]`).

```php
#[Validate('required|string|max:255')]
public string $name;

#[FromRoute]
#[Validate('required|integer|exists:users,id')]
public int $id;

#[Validate('required|email|unique:users,email,{id}')]
public string $email;
```

See [Validation](/features/validation).

### `#[Cast('type')]` and `#[Caster(class)]`

Without either attribute, casting is driven by the property's declared type: `int`, `float`, `bool`, `string`, `array`, the DateTime family and backed enums.

`#[Cast]` is for scalar types only — `int`, `float`, `bool`, `string`, `array` — and needed only where the type does not imply one. Dates follow their type; `#[DateFormat]` fixes the input format. Passing a class name throws `ConfigurationException::castExpectsBuiltInType` — use `#[Caster]` for custom logic.

```php
#[Cast('float')]
public int|float $amount;

#[DateFormat('Y-m-d')]
public DateTimeImmutable $birthday;

#[Caster(MoneyCaster::class)]
public Money $price;
```

`#[Cast]` and `#[Caster]` are mutually exclusive — both on the same property throws `ConfigurationException::castAndCasterConflict`.

See [Type Casting](/features/type-casting).

### `#[PreProcess(handler)]` and `#[PostProcess(handler, config: [...])]`

Transform values before or after validation. Handler can be:

- a global function name (`'trim'`, `'strtolower'`)
- a class implementing `ProcessorInterface` or `CasterInterface`
- a `public static` method on the Request class (non-static methods throw `ConfigurationException::processorMethodNotStatic` at build time)

```php
#[PreProcess('trim')]
public string $name;

#[PostProcess(SlugProcessor::class)]
public string $slug;

#[PostProcess(SlugProcessor::class, config: ['separator' => '-'])]
public string $slug2;
```

Inside a `ProcessorInterface`, the `config` array arrives via `ProcessContext::$config`. `$route` and `$context` are also exposed there.

See [Processors](/features/processors).

### `#[Generator(class, options: [...])]`

Generate the value instead of reading it from any source:

```php
#[Generator(UuidGenerator::class)]
public string $id;

#[Generator(SequenceGenerator::class, options: ['table' => 'orders'])]
public int $orderNumber;
```

`#[Generator]` is exclusive with `#[Validate]`, `#[PreProcess]`, `#[PostProcess]`, `#[Cast]`, `#[Caster]`, `#[Items]`, and any source attribute — they would have nothing to act on and trigger `ConfigurationException::generatorConflict` at build time.

See [Generators](/features/generators).

### `#[Items(NestedRequest::class)]`

Property is an array of nested Request DTOs. Each element is processed through the items class:

```php
#[Validate('required|array|min:1')]
#[Items(OrderItemRequest::class)]
public ?array $items = null;
```

`#[Items]` is exclusive with `#[Cast]` and `#[Caster]` (item processing replaces casting). A non-array value on an items property produces a structured `ValidationException` (`['field' => [['rule' => 'array']]]`), not a TypeError.

`$route` is **not** propagated into nested items; `$context` IS.

See [Nested Items](/features/nested-items).

### `#[Group('name', mapTo: '...')]`

Assign the property to a named group for `Request::group()` extraction:

```php
#[Group('filters')]
public ?string $search = null;

#[Group('criteria', mapTo: 'positions.id')]
public int $position_id;
```

Null-valued group members are skipped from the output.

See [Field Grouping](/features/grouping).

### `#[Exclude]`

The property goes through the input pipeline normally (validated, cast, processed) but is **omitted from `Request::toArray()`**. Useful for sensitive fields:

```php
#[Validate('required|string|min:8')]
#[Exclude]
public string $password;

// $dto->password               // "secret123" — accessible in code
// $dto->toArray()              // ['name' => 'X']  — no password leaks
```

### `#[Ignore]`

Opposite of `#[Exclude]` — `#[Ignore]` removes the property from the pipeline **entirely**. The handler does NOT read it from any source, NOT validate it, NOT cast it; `toArray()`/`has()`/`get()`/`group()` also skip it. Use for public properties that the controller assigns directly (injected services, computed values that should stay outside the DTO surface).

```php
#[Ignore]
public ?User $currentUser = null;  // controller sets this; handler ignores

#[Validate('required|string')]
public string $action;
```

Equivalent to changing the visibility to `protected`/`private`. Use `#[Ignore]` when the property needs to stay `public` (e.g. for framework introspection or serialisers that target only public properties). Cannot coexist with any other RequestHandler attribute — combining it throws `ConfigurationException::ignoreConflict`.

---

## Properties without any attribute

Every public non-static property is managed. Without attributes the property is read from the default-source bag (Body for `handleBody`/`handleArray`, Query for `handleQuery`) by name, with type-driven cast.

```php
final class UserRequest extends Request
{
    #[Validate('required|string')]
    public string $name;           // validated

    public ?string $email = null;  // read by name, no validation
    public int $age = 18;          // read by name + type-driven int cast
}
```

If you don't want a public property to be touched by the handler, change visibility to `protected`/`private` — the handler only looks at public properties:

```php
final class AuthenticatedRequest extends Request
{
    #[Validate('required|string')]
    public string $action;

    protected ?User $currentUser = null;  // controller sets this; handler ignores it
}
```

---

## Complete example

```php
use Solo\RequestHandler\Attributes\{Validate, DateFormat, PreProcess, PostProcess, Generator, Items, Group, Exclude};
use Solo\RequestHandler\Attributes\Source\{FromRoute, FromContext};

final class UpdateOrderRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer|exists:orders,id')]
    public int $id;

    #[Generator(UuidGenerator::class)]
    public string $revisionId;

    #[Validate('required|integer|exists:users,id')]
    public int $customerId;

    #[Validate('required|array|min:1')]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;

    #[Validate('nullable|string|max:500')]
    #[PreProcess('trim')]
    public ?string $notes = null;

    #[Validate('in:pending,confirmed')]
    #[Exclude]
    public string $status = 'pending';

    #[DateFormat('Y-m-d')]
    #[Group('meta')]
    public ?DateTime $deliveryDate = null;

    #[FromContext('tenantId')]
    #[Validate('required|integer')]
    public int $tenantId;

    #[FromContext('authUserId')]
    #[Validate('required|integer')]
    public int $updatedBy;

    protected ?Connection $db = null; // set by controller; handler ignores
}
```
