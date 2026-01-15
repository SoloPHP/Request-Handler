# Field Attribute API

The `#[Field]` attribute marks a property to be populated from HTTP requests.

## Definition

```php
namespace Solo\RequestHandler\Attributes;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public ?string $rules = null,
        public ?string $cast = null,
        public ?string $mapFrom = null,
        public ?string $preProcess = null,
        public ?string $postProcess = null,
        public ?string $group = null,
        public ?string $generator = null,
        public array $generatorOptions = [],
        public bool $exclude = false,
    ) {}
}
```

---

## Parameters

### rules

Validation rules as a pipe-separated string.

| Type | Default |
|------|---------|
| `?string` | `null` |

```php
#[Field(rules: 'required|string|max:255')]
public string $name;

#[Field(rules: 'nullable|email|unique:users,email')]
public ?string $email = null;
```

See [Validation](/features/validation) for available rules.

---

### cast

Explicit type casting. Overrides automatic casting based on property type.

| Type | Default |
|------|---------|
| `?string` | `null` |

**Built-in types:**
- `int`, `integer`
- `float`, `double`
- `bool`, `boolean`
- `string`
- `array`
- `datetime`, `datetime:Y-m-d`, `datetime:immutable`, `datetime:immutable:Y-m-d`

**Custom caster:**

```php
#[Field(cast: MoneyCaster::class)]
public Money $price;
```

See [Type Casting](/features/type-casting) for details.

---

### mapFrom

Dot-notation path to extract value from nested input data.

| Type | Default |
|------|---------|
| `?string` | `null` (uses property name) |

```php
// Request: {"user": {"profile": {"age": 30}}}

#[Field(mapFrom: 'user.profile.age')]
public int $userAge; // 30
```

---

### preProcess

Function or class to transform value **before** validation.

| Type | Default |
|------|---------|
| `?string` | `null` |

**Accepts:**
- Global function name: `'trim'`, `'strtolower'`
- Class implementing `ProcessorInterface`
- Class implementing `CasterInterface`
- Static method name on the Request class

```php
#[Field(preProcess: 'trim', rules: 'required|string')]
public string $name;

#[Field(preProcess: HtmlSanitizer::class)]
public string $content;
```

See [Processors](/features/processors) for details.

---

### postProcess

Function or class to transform value **after** validation and casting.

| Type | Default |
|------|---------|
| `?string` | `null` |

::: warning
When `postProcess` is defined, automatic type casting is **skipped**. The postProcessor must return the correctly typed value.
:::

```php
#[Field(rules: 'required|string', postProcess: 'strtolower')]
public string $email;

#[Field(postProcess: JsonDecoder::class)]
public array $metadata;
```

See [Processors](/features/processors) for details.

---

### group

Assign field to a named group for bulk extraction.

| Type | Default |
|------|---------|
| `?string` | `null` |

```php
#[Field(group: 'filters')]
public ?string $search = null;

#[Field(group: 'filters')]
public ?string $status = null;

// Extract all filter fields
$filters = $dto->group('filters');
```

See [Field Grouping](/features/grouping) for details.

---

### generator

Class to auto-generate field value. Must implement `GeneratorInterface`.

| Type | Default |
|------|---------|
| `?string` | `null` |

```php
#[Field(generator: UuidGenerator::class)]
public string $id;
```

**Behavior:**
- Request value is **ignored** — always generates new value
- Field **bypasses** validation rules
- Runs before casting

See [Generators](/features/generators) for details.

---

### generatorOptions

Options array passed to generator's `generate()` method.

| Type | Default |
|------|---------|
| `array` | `[]` |

```php
#[Field(
    generator: SequenceGenerator::class,
    generatorOptions: ['table' => 'orders', 'prefix' => 'ORD']
)]
public string $orderNumber;
```

---

### exclude

Exclude field from `toArray()` output.

| Type | Default |
|------|---------|
| `bool` | `false` |

```php
#[Field(rules: 'in:pending,processing', exclude: true)]
public string $internalStatus = 'pending';

$dto->toArray(); // Does NOT include 'internalStatus'
$dto->internalStatus; // Still accessible directly
```

---

## Optional Attribute

The `#[Field]` attribute is **optional**. Properties without it are still processed:

```php
final class UserRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;           // With validation

    public ?string $email = null;  // No validation, just populated
    public int $page = 1;          // No validation, has default
}
```

---

## Complete Example

```php
final class CreateOrderRequest extends Request
{
    #[Field(generator: UuidGenerator::class)]
    public string $id;

    #[Field(
        rules: 'required|integer|exists:users,id',
        mapFrom: 'customer.id'
    )]
    public int $customerId;

    #[Field(rules: 'required|array|min:1')]
    public array $items;

    #[Field(
        rules: 'nullable|string|max:500',
        preProcess: 'trim'
    )]
    public ?string $notes = null;

    #[Field(
        rules: 'in:pending,confirmed',
        exclude: true
    )]
    public string $status = 'pending';

    #[Field(
        cast: 'datetime:Y-m-d',
        group: 'meta'
    )]
    public ?DateTime $deliveryDate = null;

    #[Field(group: 'meta')]
    public ?string $couponCode = null;
}
```

---

## Configuration Validation

Invalid configurations throw `ConfigurationException` at build time:

| Error | Cause | Fix |
|-------|-------|-----|
| Nullable rule with non-nullable type | `#[Field(rules: 'nullable')]` on `string` | Use `?string` |
| Required with default | `#[Field(rules: 'required')]` on `= 'default'` | Remove default or `required` |
| Incompatible cast | `#[Field(cast: 'string')]` on `int` | Match cast to property type |
| Invalid processor | Non-existent function/class | Check spelling, implement interface |
| Invalid generator | Class doesn't implement `GeneratorInterface` | Implement the interface |
