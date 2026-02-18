---
layout: home

hero:
  name: Solo Request Handler
  text: Type-safe Request DTOs
  tagline: Transform HTTP requests into strictly typed DTOs with automatic validation, casting, and full IDE support.
  image:
    src: /logo.svg
    alt: Solo Request Handler
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/solophp/request-handler

features:
  - icon: 🎯
    title: Attribute-based DTOs
    details: Define request structures using #[Field] attributes on typed properties with full IDE autocomplete.
  - icon: 🔄
    title: Automatic Type Casting
    details: Built-in support for int, float, bool, string, array, DateTime and custom casters.
  - icon: ✅
    title: Validation Rules
    details: Validate input with expressive rules. Support for route parameter placeholders.
  - icon: ⚡
    title: Generators
    details: Auto-generate field values (UUIDs, sequences) via generator parameter with custom options.
  - icon: 🔧
    title: Pre/Post Processing
    details: Transform data before validation or after casting with processors.
  - icon: 🪆
    title: Nested Items
    details: Validate arrays of nested objects through referenced Request classes with dot-notation errors.
  - icon: 📦
    title: Field Grouping
    details: Organize fields into logical groups and extract them via group() method.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #10b981 0%, #3b82f6 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #10b98130 0%, #3b82f630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

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

// Usage in controller
$dto = $requestHandler->handle(CreateProductRequest::class, $request);

echo $dto->name;   // string - full IDE support
echo $dto->price;  // float - auto-casted
echo $dto->id;     // string - auto-generated UUID
```

## Installation

```bash
composer require solophp/request-handler
```

**Requirements:** PHP 8.2+, PSR-7 HTTP Message
