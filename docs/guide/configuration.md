# Configuration

## RequestHandler Constructor

```php
public function __construct(
    ValidatorInterface $validator,
    bool $autoTrim = true,
)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$validator` | `ValidatorInterface` | required | Validator instance |
| `$autoTrim` | `bool` | `true` | Auto-trim whitespace from string inputs |

## Auto Trim

By default, all string inputs are trimmed before validation:

```php
// Input: "  John Doe  " → Stored as: "John Doe"
#[Validate('required|string')]
public string $name;
```

**Disable globally:**

```php
$handler = new RequestHandler($validator, autoTrim: false);
```

**Or use `#[PreProcess]` for specific fields:**

```php
#[PreProcess('trim')]
public string $name;
```

## Dependency Injection

Register processor/caster/generator instances with constructor dependencies:

```php
final class SequenceGenerator implements GeneratorInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function generate(array $options = []): int
    {
        return $this->connection->getNextId($options['table']);
    }
}

$handler = new RequestHandler($validator);
$handler->register(SequenceGenerator::class, new SequenceGenerator($connection));
```

`register()` returns `$this` for chaining:

```php
$handler
    ->register(SequenceGenerator::class, new SequenceGenerator($db))
    ->register(SlugProcessor::class, new SlugProcessor($transliterator));
```

Registered (and lazily-created) instances are reused across all subsequent `handle*()` calls on the same handler. The handler is designed for classic PHP-FPM, where its lifetime equals one request — keep `ProcessorInterface` / `CasterInterface` / `GeneratorInterface` implementations free of per-request mutable state.

## Handler methods

| Method | Default source | Input |
|---|---|---|
| `handleBody(class, request, route, context)` | `Body` | `$request->getParsedBody()` |
| `handleQuery(class, request, route, context)` | `Query` | `$request->getQueryParams()` |
| `handleArray(class, data, route, context)` | `Body` | raw `array $data` |

All three accept the same optional `$route` and `$context` arrays. The "default source" is what un-attributed properties read from; properties with `#[FromRoute]` or `#[FromContext]` always use that source instead.

### handleBody()

POST/PUT/PATCH requests. Reads body via `$request->getParsedBody()`:

```php
$dto = $handler->handleBody(CreateProductRequest::class, $request);
```

### handleQuery()

GET requests. Reads from `$request->getQueryParams()`:

```php
$dto = $handler->handleQuery(SearchRequest::class, $request);
```

### handleArray()

Process raw arrays without a PSR-7 request — useful for tests, CLI commands, or non-HTTP sources:

```php
$dto = $handler->handleArray(OrderItemRequest::class, [
    'product' => 'Widget',
    'quantity' => '3',
    'price' => '9.99',
]);
```

## Route + context arguments

`handleBody()` / `handleQuery()` / `handleArray()` accept two optional bags besides the request itself:

| Argument | Purpose | Consumed by |
|---|---|---|
| `$route` | URL path parameters (`/products/{id}` → `['id' => 5]`) | `#[FromRoute]` + `ProcessContext::$route` |
| `$context` | Generic application context (auth user, tenant, feature flags) | `#[FromContext]` + `ProcessContext::$context` |

They are NOT merged into the body bag. Each lives in its own slot — collisions between body keys, route keys and context keys are impossible by construction.

For values that don't fit one of the four bags (Body, Query, Route, Context) — request headers, cookies, file uploads — extract them in the controller and pass them via `route: [...]` or `context: [...]`.

### Example: route param as a DTO property

```php
use Solo\RequestHandler\Attributes\{Validate};
use Solo\RequestHandler\Attributes\Source\FromRoute;

final class UpdateProductRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer|exists:products,id')]
    public int $id;

    #[Validate('required|string|max:255')]
    public string $name;
}

$dto = $handler->handleBody(
    UpdateProductRequest::class,
    $request,
    route: ['id' => 123],
);

$dto->id;   // 123 — pulled from $route
$dto->name; // from body
```

### Example: context value as a DTO property

```php
use Solo\RequestHandler\Attributes\Source\FromContext;

final class CreatePostRequest extends Request
{
    #[FromContext('authUserId')]
    #[Validate('required|integer')]
    public int $authorId;

    #[Validate('required|string')]
    public string $title;
}

$dto = $handler->handleBody(
    CreatePostRequest::class,
    $request,
    context: ['authUserId' => $auth->id()],
);
```

### Cross-field references in validation rules

The handler does not transform rule strings. Cross-field references in rules (e.g. `unique:users,email,{id}`) are the validator's concern.

A typical validator implementation resolves `{id}` against the validation payload — which contains every DTO field, including those marked `#[FromRoute]` / `#[FromContext]`. So declare the referenced field as a real property:

```php
final class UpdateUserRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer|exists:users,id')]
    public int $id;

    #[Validate('required|email|unique:users,email,{id}')]
    public string $email;
}

$dto = $handler->handleBody(
    UpdateUserRequest::class,
    $request,
    route: ['id' => 123],
);
// Validator sees: data = ['id' => 123, 'email' => '...'], and resolves {id} → 123
```

### Inside a `ProcessorInterface`

```php
final class SlugWithIdProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $id = $context->route['id'] ?? '';
        $tenant = $context->context['tenant'] ?? '';
        return $value . '-' . $id . '-' . $tenant;
    }
}
```

### Nested items

Route is **not** propagated into nested items processed via `#[Items]` (items describe outer-resource children, not the outer resource). Context IS propagated, so item processors can read `ProcessContext::$context`.

## Request Base Class

All Request DTOs extend `Solo\RequestHandler\Request`:

```php
use Solo\RequestHandler\Request;
use Solo\RequestHandler\Attributes\Validate;

final class MyRequest extends Request
{
    #[Validate('required|string')]
    public string $name;
}
```

The base class provides:

- `toArray()` — Convert to array (skips uninitialized and `#[Exclude]`-marked properties)
- `has(string $name)` — Check if property is initialized
- `get(string $name, mixed $default)` — Get value with default
- `group(string $name)` — Get fields by group name (see `#[Group]`)
- `clearCache()` — Drop the base-class metadata cache (test helper)
