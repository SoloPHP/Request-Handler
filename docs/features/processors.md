# Processors

Processors transform data before validation (`preProcess`) or after casting (`postProcess`).

## preProcess

Runs **before** validation. Use for sanitization:

```php
#[Field(preProcess: 'trim', rules: 'required|string')]
public string $name;

// Input: "  John  " → Validated as: "John"
```

## postProcess

Runs **after** validation and casting. Use for transformation:

```php
#[Field(rules: 'required|string', postProcess: 'strtolower')]
public string $email;

// Input: "John@Example.COM" → Stored as: "john@example.com"
```

::: warning
When `postProcess` is defined, automatic type casting is skipped. The postProcessor receives the raw validated value and must return the correctly typed result.
:::

---

## Processor Types

### Global Functions

```php
#[Field(preProcess: 'trim')]
public string $name;

#[Field(postProcess: 'strtolower')]
public string $email;

#[Field(postProcess: 'json_decode')]
public array $data;
```

### ProcessorInterface Class

```php
use Solo\RequestHandler\Contracts\ProcessorInterface;

final class SlugProcessor implements ProcessorInterface
{
    public function process(mixed $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

// Usage
#[Field(postProcess: SlugProcessor::class)]
public string $slug;
```

### CasterInterface as Processor

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
#[Field(preProcess: JsonArrayCaster::class, rules: 'array|min:1')]
public array $items;
```

### Static Method on Request

```php
final class ContactRequest extends Request
{
    #[Field(rules: 'required|string', postProcess: 'normalizePhone')]
    public string $phone;

    public static function normalizePhone(string $value): string
    {
        // Remove everything except digits and +
        return preg_replace('/[^0-9+]/', '', $value);
    }
}
```

---

## postProcessConfig

Pass configuration to a post-processor via `postProcessConfig`. The config array is passed as the second argument to `process()`:

```php
#[Field(
    rules: 'required|string',
    postProcess: CurrencyFormatter::class,
    postProcessConfig: ['currency' => 'USD', 'decimals' => 2]
)]
public string $price;
```

```php
final class CurrencyFormatter implements ProcessorInterface
{
    public function process(mixed $value, array $config = []): string
    {
        $decimals = $config['decimals'] ?? 2;
        $currency = $config['currency'] ?? 'USD';
        return number_format((float) $value, $decimals) . ' ' . $currency;
    }
}
```

::: info
`postProcessConfig` only works with `postProcess`. Pre-processors always receive just the value.
:::

---

## Processors with Dependencies

Register processor instances for dependency injection:

```php
final class TransliteratorProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Transliterator $transliterator
    ) {}

    public function process(mixed $value): string
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

## Processing Pipeline

The complete processing order:

1. **Extract** — Get value from request
2. **Auto-trim** — Trim strings (if enabled)
3. **preProcess** — Run pre-processor
4. **Validate** — Apply validation rules
5. **Cast** — Convert type (skipped if postProcess defined)
6. **postProcess** — Run post-processor
7. **Assign** — Set property value

```php
#[Field(
    preProcess: 'trim',           // Step 3
    rules: 'required|string',     // Step 4
    cast: 'string',               // Step 5 (skipped if postProcess)
    postProcess: 'strtolower'     // Step 6
)]
public string $email;
```

---

## Practical Examples

### Slug Generation

```php
final class SlugProcessor implements ProcessorInterface
{
    public function process(mixed $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}

#[Field(rules: 'required|string', postProcess: SlugProcessor::class)]
public string $slug;

// "Hello World!" → "hello-world"
```

### Phone Normalization

```php
final class PhoneProcessor implements ProcessorInterface
{
    public function process(mixed $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value);
        
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        
        return '+' . $digits;
    }
}

#[Field(rules: 'required|string', postProcess: PhoneProcessor::class)]
public string $phone;

// "(555) 123-4567" → "+15551234567"
```

### HTML Sanitization

```php
final class HtmlSanitizer implements ProcessorInterface
{
    public function process(mixed $value): string
    {
        return strip_tags($value, '<p><br><strong><em>');
    }
}

#[Field(preProcess: HtmlSanitizer::class, rules: 'required|string')]
public string $content;
```

### JSON Decoding

```php
final class JsonDecoder implements ProcessorInterface
{
    public function process(mixed $value): array
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

#[Field(postProcess: JsonDecoder::class)]
public array $metadata;
```

---

## Configuration Validation

Invalid processors throw `ConfigurationException` at build time:

```php
// ❌ Error: nonExistentFunction doesn't exist
#[Field(preProcess: 'nonExistentFunction')]
public string $value;

// ❌ Error: class doesn't implement required interface
#[Field(preProcess: SomeClass::class)]
public string $value;
```

Valid processors must be:
- Global function
- Class implementing `ProcessorInterface` or `CasterInterface`
- Static method on the Request class
