# Configuration

## RequestHandler Constructor

```php
public function __construct(
    ValidatorInterface $validator,
    bool $autoTrim = true
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
#[Field(rules: 'required|string')]
public string $name;
```

**Disable globally:**

```php
$handler = new RequestHandler($validator, autoTrim: false);
```

**Or use preProcess for specific fields:**

```php
#[Field(preProcess: 'trim')]
public string $name;
```

## Dependency Injection

Register processor/generator instances with dependencies:

```php
// Generator with database connection
class SequenceGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function generate(array $options = []): int
    {
        return $this->connection->getNextId($options['table']);
    }
}

// Register the instance
$handler = new RequestHandler($validator);
$handler->register(
    SequenceGenerator::class,
    new SequenceGenerator($connection)
);
```

The `register()` method returns `$this` for chaining:

```php
$handler
    ->register(SequenceGenerator::class, new SequenceGenerator($db))
    ->register(SlugProcessor::class, new SlugProcessor($transliterator));
```

## Route Parameters

Pass route parameters for placeholder replacement in validation rules:

```php
class UpdateProductRequest extends Request
{
    #[Field(rules: 'required|email|unique:users,email,{id}')]
    public string $email;
}

// Usage
$dto = $handler->handle(
    UpdateProductRequest::class,
    $request,
    ['id' => 123]  // Route params
);
// Rule becomes: 'required|email|unique:users,email,123'
```

Multiple placeholders are supported:

```php
#[Field(rules: 'unique:users,email,{userId},tenant_id,{tenantId}')]
public string $email;

// With routeParams: ['userId' => 42, 'tenantId' => 99]
// Rule becomes: 'unique:users,email,42,tenant_id,99'
```

## Request Base Class

All Request DTOs must extend `Solo\RequestHandler\Request`:

```php
use Solo\RequestHandler\Request;

final class MyRequest extends Request
{
    // Properties with #[Field] attributes
}
```

The base class provides:
- `toArray()` — Convert to array (excludes uninitialized and excluded fields)
- `has(string $name)` — Check if property is initialized
- `get(string $name, mixed $default)` — Get value with default
- `group(string $name)` — Get fields by group name
- `getMessages()` — Get custom validation messages
