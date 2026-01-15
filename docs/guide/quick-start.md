# Quick Start

## Create a Request DTO

Create a class extending `Request` with public typed properties:

```php
<?php

declare(strict_types=1);

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

## Handle the Request

Use `RequestHandler` to process the incoming PSR-7 request:

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
            // Validate, cast, and create DTO
            $dto = $this->requestHandler->handle(
                CreateProductRequest::class,
                $request
            );

            // Use typed data with full IDE support
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
}
```

## Accessing Properties

```php
$dto = $handler->handle(ProductRequest::class, $request);

// Direct access (recommended)
echo $dto->name;

// Check if property was in request
if ($dto->has('description')) {
    echo $dto->description;
}

// Get with default value
$desc = $dto->get('description', 'No description');

// Convert to array
$data = $dto->toArray();
```

::: warning Uninitialized Properties
If a property was not in the request and has no default value, accessing it directly will throw an Error:

```php
// If 'description' was missing and has no default:
echo $dto->description; // Error!

// Use has() or get() instead:
if ($dto->has('description')) {
    echo $dto->description;
}
```
:::

## Required vs Optional Fields

```php
// Required: must be present in request
#[Field(rules: 'required|string')]
public string $username;

// Optional: may be missing (use nullable + default)
#[Field(rules: 'string')]
public ?string $bio = null;

// Optional with default value
#[Field(rules: 'integer')]
public int $page = 1;
```

## Next Steps

- [Field Attribute](/features/field-attribute) — All attribute parameters
- [Type Casting](/features/type-casting) — Automatic type conversion
- [Validation](/features/validation) — Validation rules and messages
