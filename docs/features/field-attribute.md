# Field Attribute

The `#[Field]` attribute configures how each property is processed from HTTP requests.

## Parameters

```php
#[Field(
    rules: ?string = null,
    cast: ?string = null,
    mapFrom: ?string = null,
    preProcess: ?string = null,
    postProcess: ?string = null,
    group: ?string = null,
    generator: ?string = null,
    generatorOptions: array = [],
    exclude: bool = false,
)]
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `rules` | `?string` | Validation rules string |
| `cast` | `?string` | Explicit type casting |
| `mapFrom` | `?string` | Dot-notation path to source data |
| `preProcess` | `?string` | Function/class to run before validation |
| `postProcess` | `?string` | Function/class to run after validation |
| `group` | `?string` | Group name for bulk extraction |
| `generator` | `?string` | Class to generate field value |
| `generatorOptions` | `array` | Options passed to generator |
| `exclude` | `bool` | Exclude from `toArray()` output |

---

## rules

Validation rules as a pipe-separated string:

```php
#[Field(rules: 'required|string|max:255')]
public string $name;

#[Field(rules: 'required|email|unique:users,email')]
public string $email;

#[Field(rules: 'nullable|integer|min:0|max:100')]
public ?int $quantity = null;
```

See [Validation](/features/validation) for available rules.

---

## cast

Explicitly cast the value to a type:

```php
#[Field(cast: 'int')]
public int $count;

#[Field(cast: 'datetime:Y-m-d')]
public DateTime $birthDate;

#[Field(cast: CustomMoneyCaster::class)]
public Money $price;
```

See [Type Casting](/features/type-casting) for details.

---

## mapFrom

Map from nested input data using dot notation:

```php
// Request: {"user": {"profile": {"age": 30}}}

#[Field(mapFrom: 'user.profile.age')]
public int $userAge; // 30
```

---

## preProcess / postProcess

Transform data before or after validation:

```php
// Global function
#[Field(preProcess: 'trim')]
public string $name;

// Processor class
#[Field(postProcess: SlugProcessor::class)]
public string $slug;

// Static method on Request class
#[Field(postProcess: 'normalizePhone')]
public string $phone;

public static function normalizePhone(string $value): string
{
    return preg_replace('/[^0-9+]/', '', $value);
}
```

See [Processors](/features/processors) for details.

---

## group

Assign fields to a named group for bulk extraction:

```php
#[Field(group: 'filters')]
public ?string $search = null;

#[Field(group: 'filters')]
public ?string $status = null;

#[Field(group: 'pagination')]
public int $page = 1;

// Extract all filter fields
$filters = $dto->group('filters');
// ['search' => '...', 'status' => '...']
```

See [Field Grouping](/features/grouping) for details.

---

## generator

Auto-generate field values:

```php
#[Field(generator: UuidGenerator::class)]
public string $id;

#[Field(
    generator: SequenceGenerator::class,
    generatorOptions: ['table' => 'orders']
)]
public int $orderNumber;
```

See [Generators](/features/generators) for details.

---

## exclude

Exclude field from `toArray()` output:

```php
#[Field(rules: 'in:pending,processing', exclude: true)]
public string $internalStatus = 'pending';

// $dto->toArray() will NOT include 'internalStatus'
// But $dto->internalStatus is still accessible
```

---

## Optional Attribute

The `#[Field]` attribute is optional. Properties without it are still processed but with defaults:

```php
final class UserRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $name;           // With validation

    public ?string $email = null;  // No validation, just populated
    public int $age = 18;          // No validation, has default
}
```

---

## Complete Example

```php
final class CreateOrderRequest extends Request
{
    #[Field(generator: UuidGenerator::class)]
    public string $id;

    #[Field(rules: 'required|integer|exists:users,id', mapFrom: 'customer.id')]
    public int $customerId;

    #[Field(rules: 'required|array|min:1')]
    public array $items;

    #[Field(rules: 'nullable|string|max:500', preProcess: 'trim')]
    public ?string $notes = null;

    #[Field(rules: 'in:pending,confirmed', exclude: true)]
    public string $status = 'pending';

    #[Field(cast: 'datetime:Y-m-d', group: 'meta')]
    public ?DateTime $deliveryDate = null;

    #[Field(group: 'meta')]
    public ?string $couponCode = null;
}
```
