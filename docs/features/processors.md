# Processors

Processors transform data before validation (`#[PreProcess]`) or after casting (`#[PostProcess]`).

## `#[PreProcess]`

Runs **before** validation. Use for sanitization:

```php
use Solo\RequestHandler\Attributes\{PreProcess, Validate};

#[PreProcess('trim')]
#[Validate('required|string')]
public string $name;

// Input: "  John  " → Validated as: "John"
```

## `#[PostProcess]`

Runs **after** validation and casting. Use for transformation:

```php
use Solo\RequestHandler\Attributes\{PostProcess, Validate};

#[Validate('required|string')]
#[PostProcess('strtolower')]
public string $email;

// Input: "John@Example.COM" → Stored as: "john@example.com"
```

::: warning
When `#[PostProcess]` is defined, automatic type casting is skipped. The postProcessor receives the raw validated value and must return the correctly typed result.
:::

---

## Handler Types

### Global Functions

```php
#[PreProcess('trim')]
public string $name;

#[PostProcess('strtolower')]
public string $email;

#[PostProcess('json_decode')]
public array $data;
```

### `ProcessorInterface` Class

```php
use Solo\RequestHandler\Contracts\ProcessorInterface;
use Solo\RequestHandler\ProcessContext;

final class SlugProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

// Usage
#[PostProcess(SlugProcessor::class)]
public string $slug;
```

### `CasterInterface` as Processor

Casters can also be used as processors:

```php
use Solo\RequestHandler\Contracts\CasterInterface;

final class JsonArrayCaster implements CasterInterface
{
    public function cast(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        return json_decode($value, true) ?? [];
    }
}

// Used as preProcess
#[PreProcess(JsonArrayCaster::class)]
#[Validate('array|min:1')]
public array $items;
```

### Static Method on Request

```php
final class ContactRequest extends Request
{
    #[Validate('required|string')]
    #[PostProcess('normalizePhone')]
    public string $phone;

    public static function normalizePhone(string $value): string
    {
        return preg_replace('/[^0-9+]/', '', $value);
    }
}
```

---

## PostProcess `config`

Pass configuration to a post-processor via the `config:` named argument. It is exposed through `$context->config`:

```php
#[Validate('required|string')]
#[PostProcess(CurrencyFormatter::class, config: ['currency' => 'USD', 'decimals' => 2])]
public string $price;
```

```php
final class CurrencyFormatter implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $decimals = $context->config['decimals'] ?? 2;
        $currency = $context->config['currency'] ?? 'USD';
        return number_format((float) $value, $decimals) . ' ' . $currency;
    }
}
```

`#[PreProcess]` does not carry a config argument — if you need configurable pre-processing, use `#[PostProcess]` or split logic into a separate handler with constructor dependencies.

---

## Processors with Dependencies

Register processor instances for dependency injection:

```php
final class TransliteratorProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Transliterator $transliterator
    ) {}

    public function process(mixed $value, ProcessContext $context): string
    {
        return $this->transliterator->transliterate($value);
    }
}

// Register the instance
$handler->register(
    TransliteratorProcessor::class,
    new TransliteratorProcessor($transliterator)
);
```

---

## Accessing Extras From a Processor

`ProcessContext` exposes the same `$route` and `$context` arrays passed to `handleBody()` / `handleQuery()` via separate slots:

```php
final class SlugWithIdProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $id = $context->route['id'] ?? '';
        $tenant = $context->context['tenantId'] ?? '';
        return "{$value}-{$id}-{$tenant}";
    }
}
```

---

## Processing Pipeline

The complete processing order:

1. **Extract** — Read value from the bag the property's source attribute points to (or the default-source bag if no attribute)
2. **Auto-trim** — Trim strings (if enabled)
3. **Empty check** — `null` and `""` exit early (skip steps 4-7)
4. **preProcess** — Run pre-processor
5. **Validate** — Apply validation rules
6. **Cast** — Convert type (skipped if postProcess defined)
7. **postProcess** — Run post-processor
8. **Assign** — Set property value

::: info Early exit for null and empty string
When a field is present in the input but its value is `null` or `""`, it bypasses pre-processing, casting, and post-processing. The value is stored directly:
- `null` — set `null` for nullable types, or default value for non-nullable types with a default
- `""` — preserved as empty string, then cast per type (e.g. `""` stays `""` for `string`, becomes `null` for `int`)
:::

```php
#[PreProcess('trim')]                 // Step 4
#[Validate('required|string')]        // Step 5
#[Cast('string')]                      // Step 6 (skipped if PostProcess)
#[PostProcess('strtolower')]          // Step 7
public string $email;
```

---

## Practical Examples

### Slug Generation

```php
final class SlugProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

#[Validate('required|string')]
#[PostProcess(SlugProcessor::class)]
public string $slug;

// "Hello World!" → "hello-world"
```

### Phone Normalization

```php
final class PhoneProcessor implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value);

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        return '+' . $digits;
    }
}

#[Validate('required|string')]
#[PostProcess(PhoneProcessor::class)]
public string $phone;

// "(555) 123-4567" → "+15551234567"
```

### HTML Sanitization

```php
final class HtmlSanitizer implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): string
    {
        return strip_tags($value, '<p><br><strong><em>');
    }
}

#[PreProcess(HtmlSanitizer::class)]
#[Validate('required|string')]
public string $content;
```

### JSON Decoding

```php
final class JsonDecoder implements ProcessorInterface
{
    public function process(mixed $value, ProcessContext $context): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $decoded;
    }
}

#[PostProcess(JsonDecoder::class)]
public array $metadata;
```

---

## Configuration Validation

Invalid processors throw `ConfigurationException` at build time:

```php
// ❌ Error: nonExistentFunction doesn't exist
#[PreProcess('nonExistentFunction')]
public string $value;

// ❌ Error: class doesn't implement required interface
#[PreProcess(SomeClass::class)]
public string $value;
```

Valid processors must be:
- Global function
- Class implementing `ProcessorInterface` or `CasterInterface`
- Static method on the Request class
