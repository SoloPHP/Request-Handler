# Quick Start

## Create a Request DTO

Create a class extending `Request` with public typed properties. All public non-static properties are managed automatically — attributes only add behaviour.

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Request;

final class CreateProductRequest extends Request
{
    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('required|numeric|min_value:0')]
    public float $price;

    #[Validate('integer|min_value:0')]
    public int $stock = 0;

    #[Validate('nullable|string')]
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
            $dto = $this->requestHandler->handleBody(
                CreateProductRequest::class,
                $request
            );

            $this->productService->create(
                name: $dto->name,
                price: $dto->price,
                stock: $dto->stock,
                description: $dto->description,
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
$dto = $handler->handleBody(ProductRequest::class, $request);

// Direct access (recommended)
echo $dto->name;

// Check if property was in request
if ($dto->has('description')) {
    echo $dto->description;
}

// Get with default value
$desc = $dto->get('description', 'No description');

// Convert to array (skips uninitialized + #[Exclude]-marked properties)
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
#[Validate('required|string')]
public string $username;

// Optional: may be missing (use nullable + default)
#[Validate('nullable|string')]
public ?string $bio = null;

// Optional with default value
#[Validate('integer')]
public int $page = 1;

// No #[Validate] — handler just reads/casts the value, validator is never called
public ?string $internal = null;
```

::: tip Null vs Empty String
The handler distinguishes absent fields, explicit `null`, and empty string `""`. See [Validation — Absent vs Null vs Empty String](/features/validation#absent-vs-null-vs-empty-string) for details.
:::

## Next Steps

- [Configuration](/guide/configuration) — `handleBody` / `handleQuery` / route + context
- [Type Casting](/features/type-casting) — Automatic type conversion
- [Validation](/features/validation) — Validation rules and messages
- [Processors](/features/processors) — Pre/post processing
