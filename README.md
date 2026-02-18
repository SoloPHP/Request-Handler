# Solo Request Handler

Type-safe Request DTOs for PHP 8.2+ with automatic validation, type casting, and full IDE support.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Attribute-based DTOs** — Define request structures using `#[Field]` attributes
- **Automatic Type Casting** — Built-in support for int, float, bool, string, array, DateTime
- **Validation Rules** — Expressive rules with route parameter placeholders
- **Generators** — Auto-generate UUIDs, sequences, and custom values
- **Pre/Post Processing** — Transform data before validation or after casting
- **Nested Items** — Validate arrays of nested objects through referenced Request classes
- **Field Grouping** — Organize fields into logical groups

## Installation

```bash
composer require solophp/request-handler
```

## Quick Example

```php
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

    #[Field(generator: UuidGenerator::class)]
    public string $id;
}

// In controller
$dto = $requestHandler->handle(CreateProductRequest::class, $request);

$dto->name;   // string - full IDE support
$dto->price;  // float - auto-casted
$dto->id;     // string - auto-generated UUID
```

## Documentation

**[Full Documentation](https://solophp.github.io/Request-Handler/)**

- [Installation](https://solophp.github.io/Request-Handler/guide/installation)
- [Quick Start](https://solophp.github.io/Request-Handler/guide/quick-start)
- [Field Attribute](https://solophp.github.io/Request-Handler/features/field-attribute)
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
