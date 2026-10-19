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
    title: Explicit source binding
    details: Default source comes from the handle method (Body / Query). #[FromRoute] and #[FromContext] override per property. No implicit merging.
  - icon: 🧩
    title: Composable attributes
    details: Each concern is its own focused attribute — #[Validate], #[Cast], #[PostProcess], #[Generator], #[Items], …
  - icon: 🔄
    title: Automatic type casting
    details: Driven by declared type — int, float, bool, string, array, DateTime, backed enums + custom casters. Unconvertible input is a validation error, not garbage.
  - icon: ✅
    title: Validation rules
    details: Rules passed verbatim to your ValidatorInterface. Every field present in the request — route and context included — lands in the validation payload. All errors come back at once.
  - icon: ⚡
    title: Generators
    details: Auto-generate field values (UUIDs, sequences) via #[Generator] with custom options.
  - icon: 🔧
    title: Pre/post processing
    details: Transform values before validation or after it via #[PreProcess] / #[PostProcess].
  - icon: 🪆
    title: Nested items
    details: Validate arrays of nested DTOs via #[Items] with dot-notation error paths.
  - icon: 🛡️
    title: Fail-fast
    details: Misconfigurations throw ConfigurationException at metadata-build time, nested item classes included.
  - icon: 📐
    title: Schema export
    details: schema() emits JSON-serializable per-field metadata (type, nullability, requiredness incl. required_if, normalized rules) — the backend as a single source of truth for the frontend.
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
use Solo\RequestHandler\Attributes\{Validate, Generator};
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Request;

final class UpdateProductRequest extends Request
{
    // PUT /products/{id}
    #[FromRoute]
    #[Validate('required|integer|exists:products,id')]
    public int $id;

    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('required|numeric|min_value:0')]
    public float $price;

    #[Validate('integer|min_value:0')]
    public int $stock = 0;

    #[Generator(UuidGenerator::class)]
    public string $sku;
}

$dto = $requestHandler->handleBody(
    UpdateProductRequest::class,
    $request,
    route: ['id' => $productId],
);

echo $dto->id;     // int — from URL path
echo $dto->name;   // string — from body
echo $dto->price;  // float — auto-casted
echo $dto->sku;    // string — auto-generated UUID
```

## Installation

```bash
composer require solophp/request-handler
```

**Requirements:** PHP 8.3+, PSR-7 HTTP Message
