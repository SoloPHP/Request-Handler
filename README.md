# Request Handler

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)]()

**Type-safe Request DTOs for PHP 8.2+**

Transform raw HTTP requests into strictly typed Data Transfer Objects (DTOs) with automatic validation, type casting, and full IDE support.

---

## Features

- **Attribute-based DTOs:** Define request structures using `#[Field]` attributes on typed properties
- **Type-Safe Properties:** Full IDE autocomplete and static analysis support with strict typing
- **Automatic Type Casting:** Built-in support for `int`, `float`, `bool`, `string`, `array`, and `DateTime`/`DateTimeImmutable`
- **UUID Generation:** Auto-generate UUID v4 for fields with `uuid: true` parameter
- **Route Parameter Placeholders:** Use `{placeholder}` syntax in validation rules with `$routeParams` injection
- **Field Grouping:** Organize fields into logical groups with `group` parameter and extract via `group()` method
- **Nested Mapping:** Map deeply nested input data to flat properties via `mapFrom` with dot notation
- **Processing Pipeline:** Support for `preProcess` and `postProcess` hooks (functions, classes, or static methods)
- **Auto Trim:** Automatic whitespace trimming for string inputs (configurable)
- **Custom Messages:** Override validation error messages via `messages()` method
- **Configuration Validation:** Early detection of invalid configurations via `ConfigurationException`
- **Reflection Caching:** Optimized performance with cached metadata

---

## Installation

```bash
composer require solophp/request-handler
```

---

## Quick Start

### 1. Define a Request DTO

Create a class extending `Request` and define your public properties with the `#[Field]` attribute.

```php
<?php

namespace App\Requests;

use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Request;

final class CreateProductRequest extends Request
{
    #[Field(rules: 'required|string|max:255')]
    public string $name;

    #[Field(rules: 'required|numeric|min:0')]
    public float $price;

    #[Field(rules: 'nullable|integer|min:0')]
    public int $stock = 0;

    #[Field(rules: 'nullable|string')]
    public ?string $description = null;
}
```

### 2. Handle the Request

Inject `RequestHandler` and use it to process the incoming PSR-7 request.

```php
use App\Requests\CreateProductRequest;
use Solo\RequestHandler\RequestHandler;
use Solo\RequestHandler\Exceptions\ValidationException;

class ProductController
{
    public function __construct(
        private readonly RequestHandler $requestHandler,
        private readonly ProductService $productService,
    ) {}

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // 1. Validate, Cast, and Create DTO
            $dto = $this->requestHandler->handle(CreateProductRequest::class, $request);

            // 2. Use Typed Data (Full IDE Support)
            $this->productService->create(
                name: $dto->name,          // string
                price: $dto->price,        // float
                stock: $dto->stock,        // int
                description: $dto->description // ?string
            );

            return $this->json(['status' => 'created'], 201);

        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->getErrors()], 422);
        }
    }

    // For update operations with route parameters:
    public function update(ServerRequestInterface $request, int $id): ResponseInterface
    {
        try {
            // Pass route params for placeholder replacement in rules
            $dto = $this->requestHandler->handle(
                UpdateProductRequest::class,
                $request,
                ['id' => $id]  // {id} in rules will be replaced with actual value
            );

            $this->productService->update($id, $dto);
            return $this->json(['status' => 'updated']);

        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->getErrors()], 422);
        }
    }
}
```

### Important: Accessing Uninitialized Properties

If a property was not present in the request (and has no default value), accessing it directly will cause a PHP Error.

```php
$dto = $handler->handle(ProductRequest::class, $request);

// If 'description' was missing in the request:
echo $dto->description; // Error: Typed property must not be accessed before initialization

// Correct way to check:
if (isset($dto->description)) { // or $dto->has('description')
    echo $dto->description;
}

// Or get with default value:
$desc = $dto->get('description', 'No description');
```

---

## Documentation

### The `#[Field]` Attribute

The `#[Field]` attribute is the core of this library. It tells the handler how to process each property.

| Parameter     | Description                          | Example              |
|:--------------|:-------------------------------------|:---------------------|
| `rules`       | Validation rules string.             | `'required\|email'`  |
| `cast`        | Explicit type casting (optional).    | `'datetime:Y-m-d'`   |
| `mapFrom`     | Dot-notation path to source data.    | `'user.profile.id'`  |
| `group`       | Group name for bulk extraction.      | `'filters'`          |
| `preProcess`  | Function to run *before* validation. | `'trim'`             |
| `postProcess` | Function to run *after* validation.  | `'strtolower'`       |
| `uuid`        | Auto-generate UUID v4 for the field. | `true`               |
| `exclude`     | Exclude field from `toArray()` output. | `true`             |

### Common Scenarios

#### Required vs. Optional Fields

- **Required**: Must be present in the request. Use `rules: 'required'`.
- **Optional**: May be missing. Do **not** use `required`.
- **Nullable**: Can be present but `null`. Use `rules: 'nullable'`.

```php
// 1. Required (Must be present, cannot be null)
#[Field(rules: 'required|string')]
public string $username;

// 2. Optional (Can be missing, defaults to null)
#[Field(rules: 'string')]
public ?string $bio = null;

// 3. Required Nullable (Must be present, but can be null)
#[Field(rules: 'required|nullable|string')]
public ?string $reason; // No default value

// 4. Optional with Default
#[Field(rules: 'integer')]
public int $page = 1;
```

#### Automatic Type Casting

The library automatically casts input values based on the PHP property type.

**Boolean Casting Table**

| Input Value                                                              | Result  |
|:-------------------------------------------------------------------------|:--------|
| `true`, `"true"`, `"1"`, `"on"`, `"yes"`                                 | `true`  |
| `false`, `"false"`, `"0"`, `"off"`, `"no"`, `""` (empty string), `null`  | `false` |
| Any other non-empty string                                               | `true`  |

**Array Casting Logic**

The library attempts to intelligently convert strings to arrays:

1. **JSON**: If valid JSON array/object (`[1,2]` or `{"a":1}`), it decodes it.
2. **CSV**: If string contains commas (`a,b,c`), it explodes and trims it.
3. **Single Value**: If non-empty string (`hello`), wraps it in array (`['hello']`).
4. **Empty**: Empty string becomes empty array `[]`.

```php
public array $tags;

// "tag1, tag2" -> ["tag1", "tag2"]
// "[\"a\", \"b\"]" -> ["a", "b"]
// "hello" -> ["hello"]
```

#### DateTime Casting

Use the `cast` parameter for date conversions.

```php
#[Field(cast: 'datetime:Y-m-d H:i:s')]
public DateTime $eventDate;

#[Field(cast: 'datetime:immutable:Y-m-d')]
public DateTimeImmutable $birthDate;
```

#### Nested Data Mapping

Map deeply nested JSON or array structures to flat properties.

```php
// Request: {"user": {"details": {"age": 30}}}

#[Field(mapFrom: 'user.details.age')]
public int $userAge; // 30
```

#### Field Grouping

Group related fields to extract them together. Returns a flat array:
- **Array properties**: contents are merged into result
- **Scalar properties**: added by property name

```php
class SearchRequest extends Request
{
    #[Field(group: 'criteria')]
    public array $search = [];

    #[Field(group: 'criteria')]
    public array $filters = [];

    #[Field(group: 'criteria')]
    public int $limit = 10;
}

// Given: $dto->search = ['name' => ['LIKE', '%test%']]
//        $dto->filters = ['status' => 'active']
//        $dto->limit = 20

$criteria = $dto->group('criteria');
// Result: [
//     'name' => ['LIKE', '%test%'],  // merged from $search
//     'status' => 'active',           // merged from $filters
//     'limit' => 20                   // scalar by property name
// ]
```

**Note:** A `LogicException` is thrown if duplicate keys are detected across properties in the same group.

#### Auto Trim

By default, `RequestHandler` automatically trims whitespace from all string inputs before validation.

```php
// Input: "  John Doe  " -> Stored as: "John Doe"
#[Field(rules: 'required|string')]
public string $name;
```

**Disable Auto Trim:**

```php
// Globally disable auto-trim
$handler = new RequestHandler($validator, autoTrim: false);

// Or use preProcess for specific fields when autoTrim is disabled
#[Field(preProcess: 'trim')]
public string $name;
```

#### Auto-Generated UUIDs

Use `uuid: true` to automatically generate a UUID v4 for a field. This is useful for creating new entities with unique identifiers.

```php
class CreateOrderRequest extends Request
{
    #[Field(uuid: true)]
    public string $id;

    #[Field(rules: 'required|string')]
    public string $productName;
}

// Usage:
$dto = $handler->handle(CreateOrderRequest::class, $request);
echo $dto->id; // "550e8400-e29b-41d4-a716-446655440000"
```

**Notes:**
- When `uuid: true` is set, any value provided in the request for this field is ignored - a new UUID is always generated.
- UUID fields must have `string` type. A `ConfigurationException` will be thrown if the type is different.

#### Excluded Fields

Use `exclude: true` to exclude a field from `toArray()` output. The field will still be populated from request data and validated normally.

```php
#[Field(rules: 'in:pending,processing', exclude: true)]
public string $status = 'pending';

// Request {"status": "processing"} will set $dto->status = "processing"
// $dto->toArray() will NOT include 'status'
```

#### Route Parameter Placeholders

Use `{placeholder}` syntax in validation rules to inject route parameters. This is useful for update operations where you need to exclude the current record from unique checks.

```php
class UpdateUserRequest extends Request
{
    #[Field(rules: 'required|email|unique:users,email,{id}')]
    public string $email;
}

// Usage:
$dto = $handler->handle(
    UpdateUserRequest::class,
    $request,
    ['id' => 123]  // Route params
);
// Rule becomes: 'required|email|unique:users,email,123'
```

Multiple placeholders are supported:

```php
#[Field(rules: 'required|unique:users,email,{userId},tenant_id,{tenantId}')]
public string $email;

// With routeParams: ['userId' => 42, 'tenantId' => 99]
// Rule becomes: 'required|unique:users,email,42,tenant_id,99'
```

#### Pre & Post Processing

Transform data before or after validation.

```php
use Solo\RequestHandler\Casters\PostProcessorInterface;

class SlugProcessor implements PostProcessorInterface
{
    public function process(mixed $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($value)));
    }
}

class ArticleRequest extends Request
{
    #[Field(postProcess: SlugProcessor::class)]
    public string $slug;

    // Or use a static method reference
    #[Field(preProcess: 'trim')]
    public string $title;
}
```

**Note:** When `postProcess` is defined, automatic type casting based on property type is skipped. The postProcessor receives the raw (validated) value and is responsible for returning the correctly typed result.

---

## Advanced Usage

### Custom Casters

Create complex type conversions by implementing `CasterInterface`.

```php
use Solo\RequestHandler\Casters\CasterInterface;

class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value): Money
    {
        return Money::fromFloat((float) $value);
    }
}

// Usage
#[Field(cast: MoneyCaster::class)]
public Money $price;
```

### Accessing Data

The `Request` object provides several methods to access data safely.

```php
$dto = $handler->handle(MyRequest::class, $request);

// 1. Direct Access (Recommended)
echo $dto->name;

// 2. Check Initialization (for optional fields)
if (isset($dto->bio)) { ... }
// OR
if ($dto->has('bio')) { ... }

// 3. Get with Default
echo $dto->get('bio', 'No bio provided');

// 4. Convert to Array
$data = $dto->toArray();
```

---

## Configuration Validation

The library validates your DTO configuration at runtime to prevent logical errors.

```php
use Solo\RequestHandler\Exceptions\ConfigurationException;

try {
    $dto = $handler->handle(InvalidRequest::class, $request);
} catch (ConfigurationException $e) {
    // Developer error - invalid DTO configuration
    // Log this and return 500
    error_log($e->getMessage());
    return $this->json(['error' => 'Internal Server Error'], 500);
} catch (ValidationException $e) {
    // User error - invalid input data
    return $this->json(['errors' => $e->getErrors()], 422);
}
```

| Error                    | Cause                                            | Fix                                          |
|:-------------------------|:-------------------------------------------------|:---------------------------------------------|
| `ConfigurationException` | `nullable` rule on non-nullable property.        | Make property nullable: `?string`.           |
| `ConfigurationException` | `required` rule on property with default value.  | Remove default value OR remove `required`.   |
| `ConfigurationException` | Incompatible `cast` type.                        | Ensure cast type matches property type.      |
| `ConfigurationException` | `uuid: true` on non-string property.             | Change property type to `string`.            |

---

## Best Practices

1. **Always initialize nullable properties**: Use `public ?string $bio = null;` instead of `public ?string $bio;` to avoid uninitialized access errors if you want them to be optional by default.
2. **Use `strict_types`**: Always add `declare(strict_types=1);` to your DTO files.
3. **Group related fields**: Use the `group` parameter for filters, pagination, or sorting parameters.
4. **Validate after casting**: Remember that validation runs *before* casting, but you can use `preProcess` to modify data before validation if needed.
5. **Test edge cases**: Ensure your DTO handles `null`, empty strings, and unexpected types gracefully.

---

## Validator Requirements

This package requires a validator implementing `Solo\Contracts\Validator\ValidatorInterface`.

We recommend using [solophp/validator](https://github.com/SoloPHP/Validator) which implements this interface and supports all required rules:

```bash
composer require solophp/validator
```

### Required Rules

| Rule       | Description                        | Example      |
|:-----------|:-----------------------------------|:-------------|
| `required` | Field must be present and not empty | `'required'` |
| `nullable` | Field can be `null`                | `'nullable'` |

### Recommended Rules

These rules are commonly used with Request Handler:

| Rule           | Description                   | Example              |
|:---------------|:------------------------------|:---------------------|
| `string`       | Value must be a string        | `'string'`           |
| `integer`      | Value must be an integer      | `'integer'`          |
| `numeric`      | Value must be numeric         | `'numeric'`          |
| `email`        | Value must be a valid email   | `'email'`            |
| `min:n`        | Minimum length                | `'min:1'`            |
| `max:n`        | Maximum length                | `'max:255'`          |
| `min_value:n`  | Minimum numeric value         | `'min_value:0'`      |
| `max_value:n`  | Maximum numeric value         | `'max_value:100'`    |
| `in:a,b,c`     | Value must be in list         | `'in:active,inactive'` |
| `boolean`      | Value must be boolean-like    | `'boolean'`          |
| `array`        | Value must be an array        | `'array'`            |
| `length:n`     | Exact length                  | `'length:10'`        |
| `date`         | Value must be a valid date    | `'date'`             |
| `date_format:f`| Value must match date format  | `'date_format:Y-m-d'` |

All these rules are supported by [solophp/validator](https://github.com/SoloPHP/Validator)

---

## FAQ

**Q: Why do I get an Error when accessing a property?**
A: The property was not present in the request and has no default value. Use `isset($dto->prop)`, `$dto->has('prop')`, or `$dto->get('prop', 'default')`.

**Q: How do I handle an array of objects?**
A: Use a custom caster that iterates through the array and instantiates objects.

**Q: Can I use union types?**
A: Yes, but the automatic casting will try to cast to the most appropriate type. Ensure your logic handles the result.

**Q: How do I integrate with Laravel/Symfony validators?**
A: Create an adapter class that implements `Solo\Contracts\Validator\ValidatorInterface` and wraps your framework's validator. See the "Validator Requirements" section above for examples.

---

## Migration from Arrays

If you are migrating from `$request->input()` or `$_POST` arrays:

**Before:**
```php
$name = $request->input('name', 'default');
$age = (int) $request->input('age', 0);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new ValidationException('Invalid email');
}
```

**After:**
```php
#[Field(rules: 'required|string|max:255')]
public string $name;

#[Field(rules: 'integer|min:0')]
public int $age = 0;

#[Field(rules: 'required|email')]
public string $email;
```

**Benefits:**
- Automatic validation
- Strict typing
- IDE Autocomplete
- No more "magic strings" for array keys

---
## License

MIT License. See [LICENSE](LICENSE) for details.
