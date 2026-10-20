# Processors

Processors transform data before validation (`#[PreProcess]`) or after it (`#[PostProcess]`).

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

What a post-processor or custom caster returns is assigned the way PHP assigns any value — `true` to an `int` property becomes `1`. A result the property cannot hold, or `null` for a property that is neither nullable nor has a default, is a bug in the handler: it throws `UnexpectedValueException` naming the handler, whatever the source of the property. To reject the client's input, throw `ValidationException` instead — see [Rejecting Input From a Handler](#rejecting-input-from-a-handler).

Post-processors run only once the **whole** input — nested items included — has passed validation, so an expensive handler (password hashing, image processing) never runs for a rejected request.

## Type Guard for Functions and Static Methods

A pre-processor sees raw client input, before validation had a chance to reject it. For a global function or static method, the handler checks the declared type of its first parameter and **skips** the handler when the value does not fit, instead of letting PHP throw a `TypeError` (a 500):

```php
#[PreProcess('strtolower')]          // strtolower(string $string)
#[Validate('required|email')]
public string $email;

// {"email": "John@Example.COM"} → "john@example.com"
// {"email": 42}                 → strtolower is skipped, the validator reports 'email' (422)
```

Query strings, route values and form fields carry only strings, so a string the parameter's type spells without loss is passed as that type instead of skipping the handler — the lossless conversion the casts make, in PHP's coercion order:

```php
#[PreProcess('clamp')]               // clamp(int $value): int
public int $limit = 10;

// ?limit=5000 → clamp(5000) → 100   ("5000" spells an int)
// ?limit=9.5  → clamp is skipped, the cast reports 'integer' (422)
```

Only strings are converted: `42` still skips `strtolower()`.

The same guard applies to post-processors: `#[PostProcess('strtoupper')]` on a `?string` leaves an explicit `null` as `null`. `ProcessorInterface` / `CasterInterface` handlers take `mixed` and are always called.

When the property's type is a class only that pre-processor produces, the handler is its only way in. Input of another type is converted to the parameter's type first when nothing is lost — as for a union property — so a query string's `"999"` reaches `fromCents(int $cents)` as `999`. Input that fits no way reports the rule for the type the pre-processor takes — unless a validation rule rejected the value first:

```php
#[PreProcess('parseMoney')]          // parseMoney(string $value): Money
public ?Money $price = null;

// {"price": "9.99"}   → Money(999)
// {"price": 9.99}     → Money(999) — the number reaches parseMoney() as "9.99"
// {"price": ""}       → null — "" is no value, the pre-processor does not see it
// {"price": ["9.99"]} → ['price' => [['rule' => 'string']]] (422)
```

A pre-processor whose parameter takes none of the types request input has — a class, `object` or `callable` — cannot be the only producer of such a property, so building the metadata throws `ConfigurationException::preProcessorTakesNoInput`. On a `#[FromContext]` property it is fine: the application supplies the object.

## Rejecting Input From a Handler

A pre-processor, post-processor or custom caster may throw `ValidationException` itself. Its errors are merged with the others of the same pass instead of aborting it, and inside `#[Items]` they are reported under the item path (`lines.1.amount`):

```php
final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value): Money
    {
        if (!is_numeric($value)) {
            throw new ValidationException(['amount' => [['rule' => 'money']]]);
        }
        return Money::fromDecimal((string) $value);
    }
}
```

---

## Handler Types

### Global Functions

```php
#[PreProcess('trim')]
public string $name;

#[PostProcess('strtolower')]
public string $email;

#[PostProcess('ucfirst')]
public string $title;
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

The method must be `public static`. It is looked up on the class being handled, so a property declared on an abstract base request can use a hook defined by each subclass.

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

Processors with constructor dependencies come from the PSR-11 container passed to `RequestHandler` (`new RequestHandler($validator, container: $container)`), or from an instance you register:

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

A handler class that is abstract or needs constructor arguments, is not registered and is not in the container fails on first use with `ConfigurationException::handlerNotInstantiable`. See [Dependency Injection](/guide/configuration#dependency-injection).

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

// ❌ Error: explode() cannot be called with the value alone
#[PreProcess('explode')]
public array $value;

// ❌ Error: the static method is private/protected
#[PreProcess('normalize')]
public string $value;
private static function normalize(string $v): string { /* ... */ }
```

Valid processors must be:
- Global function callable with a single argument
- Class implementing `ProcessorInterface` or `CasterInterface`
- `public static` method on the Request class
