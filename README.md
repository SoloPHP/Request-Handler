# Request Handler

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)]()

Dynamic request DTOs with validation for PHP 8.2+. Define your request structure once using class-level attributes, get only present fields in response.

## Features

- **Dynamic DTOs** - only fields present in the request become properties
- **Class-level field definitions** - clean syntax with repeatable `#[Field]` attributes
- **Field grouping** - organize fields into logical groups for easy extraction
- **Automatic type casting** - built-in casters for int, float, bool, array, datetime
- **Custom casters** - extend with your own type converters
- **Pre/Post processing** - transform values before validation or after
- **Nested input mapping** - access deeply nested request data with dot notation
- **Default values** - define fallback values for missing fields
- **Reflection caching** - metadata parsed once and cached for performance
- **PSR-7 compatible** - works with any PSR-7 HTTP message implementation
- **Vendor-independent validation** - use any validator implementing the interface

## Installation

```bash
composer require solophp/request-handler
```

## Quick Start

### Define a Request DTO

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\DynamicRequest;

#[AsRequest]
#[Field('name', 'required|string|max:255')]
#[Field('price', 'required|numeric|min:0', cast: 'float')]
#[Field('stock', 'nullable|integer|min:0', cast: 'int', default: 0)]
#[Field('description', 'nullable|string')]
final class CreateProductRequest extends DynamicRequest
{
}
```

### Use in Controller

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Requests\CreateProductRequest;
use Solo\RequestHandler\RequestHandler;
use Solo\RequestHandler\Exceptions\ValidationException;

final class ProductController
{
    public function __construct(
        private readonly RequestHandler $requestHandler,
        private readonly ProductService $productService,
    ) {}

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $data = $this->requestHandler->handle(CreateProductRequest::class, $request);

            // $data only contains properties that were in the request
            $product = $this->productService->create($data->toArray());

            return $this->json(['id' => $product->id], 201);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->getErrors()], 422);
        }
    }
}
```

## The #[Field] Attribute

All field configuration is done via the `#[Field]` attribute at class level:

```php
#[Field(
    name: 'fieldName',           // Property name (required)
    rules: 'required|string',    // Validation rules (optional)
    cast: 'int',                 // Type casting (optional)
    mapFrom: 'input.path',       // Input path with dot notation (optional)
    default: 'value',            // Default value (optional)
    preProcess: 'trim',          // Pre-processor (optional)
    postProcess: 'strtolower',   // Post-processor (optional)
    group: 'groupName',          // Field group for extraction (optional)
)]
```

### Examples

```php
#[AsRequest]
// Simple required field
#[Field('name', 'required|string|max:255')]

// Field with type casting
#[Field('price', 'required|numeric', cast: 'float')]

// Optional field with default
#[Field('page', 'integer|min:1', cast: 'int', default: 1)]

// Map from nested input
#[Field('userId', 'required|integer', cast: 'int', mapFrom: 'user.id')]

// Pre-process before validation
#[Field('email', 'required|email', preProcess: 'trim')]

// Post-process after validation
#[Field('slug', 'required|string', postProcess: 'strtolower')]

// Group fields for extraction
#[Field('search', 'nullable|string', group: 'criteria')]
#[Field('deleted', 'nullable|in:only,with', group: 'criteria')]
final class MyRequest extends DynamicRequest
{
}
```

## Field Grouping

Group related fields together and extract them as an array:

```php
#[AsRequest]
#[Field('search', 'nullable|string', group: 'criteria')]
#[Field('status', 'nullable|in:active,inactive', group: 'criteria')]
#[Field('page', 'integer|min:1', cast: 'int', default: 1)]
#[Field('per_page', 'integer|min:1|max:100', cast: 'int', default: 20)]
final class UserIndexRequest extends DynamicRequest
{
}

// In controller:
$data = $this->requestHandler->handle(UserIndexRequest::class, $request);

// Extract all criteria fields at once
$criteria = $data->group('criteria');
// ['search' => 'john', 'status' => 'active']

// Use for repository queries
$users = $this->userRepository->findBy(
    $criteria,
    $data->per_page,
    $data->page
);
```

## DynamicRequest Methods

The `DynamicRequest` base class provides the following methods:

```php
$data = $handler->handle(ProductRequest::class, $request);

// Access property directly (throws Error if not present)
$name = $data->name;

// Check if property exists
if (isset($data->description)) {
    // ...
}

// Check with method
if ($data->has('description')) {
    // ...
}

// Get with default fallback
$description = $data->get('description', 'No description');

// Convert to array (only present fields)
$array = $data->toArray();

// Get fields by group
$criteria = $data->group('criteria');
```

### Behavior

Only fields present in the HTTP request become properties:

```php
#[AsRequest]
#[Field('name', 'required|string')]
#[Field('description', 'nullable|string')]
final class ProductRequest extends DynamicRequest
{
}

// Request: POST /products with body: {"name": "Widget"}
$data = $handler->handle(ProductRequest::class, $request);

$data->name;        // "Widget"
$data->description; // Error: Undefined property
isset($data->name);        // true
isset($data->description); // false
$data->toArray();   // ['name' => 'Widget']
```

## Type Casting

**Built-in types:**

| Cast | Converts to |
|------|-------------|
| `int`, `integer` | integer |
| `float`, `double` | float |
| `bool`, `boolean` | boolean (`"true"`, `"1"`, `"yes"`, `"on"` → `true`) |
| `string` | string |
| `array` | array (parses JSON or comma-separated string) |
| `datetime` | DateTime object |
| `datetime:Y-m-d` | DateTime with specific format |

**Custom casters:**

```php
use Solo\RequestHandler\Casters\CasterInterface;

final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value): Money
    {
        return new Money((int) round((float) $value * 100));
    }
}

// Usage
#[Field('amount', 'required|numeric', cast: MoneyCaster::class)]
```

## Processing Pipeline

1. **Extract** - Merge POST body and GET parameters (body takes priority)
2. **Map** - Resolve values using `mapFrom` paths
3. **PreProcess** - Transform raw input before validation
4. **Validate** - Check against validation rules
5. **Cast** - Convert to target types
6. **PostProcess** - Apply final transformations
7. **Create** - Build DTO with only present fields

## Pre/Post Processing

```php
// Global function
#[Field('name', 'required|string', preProcess: 'trim')]

// Static method in the DTO class
#[Field('phone', 'required|string', preProcess: 'normalizePhone')]
final class ContactRequest extends DynamicRequest
{
    public static function normalizePhone(string $value): string
    {
        return preg_replace('/[^0-9+]/', '', $value);
    }
}

// External processor class
#[Field('slug', 'required|string', preProcess: SlugNormalizer::class)]
```

Processor classes must implement `PostProcessorInterface`:

```php
use Solo\RequestHandler\Casters\PostProcessorInterface;

final class SlugNormalizer implements PostProcessorInterface
{
    public function process(mixed $value): mixed
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value));
    }
}
```

## Error Handling

```php
use Solo\RequestHandler\Exceptions\ValidationException;

try {
    $data = $this->requestHandler->handle(MyRequest::class, $request);
} catch (ValidationException $e) {
    // $e->getErrors() returns: ['field' => ['Error message 1', 'Error message 2']]
    return $this->json([
        'success' => false,
        'errors' => $e->getErrors(),
    ], 422);
}
```

## Full Example

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Solo\RequestHandler\Attributes\AsRequest;
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\DynamicRequest;

#[AsRequest]
#[Field('customerId', 'required|integer|min:1', cast: 'int', mapFrom: 'customer.id')]
#[Field('items', 'required|array|min:1')]
#[Field('total', 'required|numeric|min:0', cast: 'float', postProcess: 'roundTotal')]
#[Field('notes', 'nullable|string|max:500', preProcess: 'trim')]
#[Field('status', 'nullable|string|in:pending,confirmed,shipped', default: 'pending')]
#[Field('deliveryDate', 'nullable|date', cast: 'datetime:Y-m-d')]
final class CreateOrderRequest extends DynamicRequest
{
    public static function roundTotal(float $value): float
    {
        return round($value, 2);
    }
}
```

## Dependencies

- PHP 8.2+
- [PSR-7 HTTP Message Interface](https://github.com/php-fig/http-message) (`psr/http-message` ^2.0)
- Any validator implementing `Solo\Contracts\Validator\ValidatorInterface`

### Suggested Validators

- [Solo Validator](https://github.com/solophp/validator) (`solophp/validator`)

## License

MIT License - See [LICENSE](LICENSE) for details.
