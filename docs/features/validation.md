# Validation

Validation rules are declared via the `#[Validate]` attribute.

## Basic Rules

```php
use Solo\RequestHandler\Attributes\Validate;

#[Validate('required|string|max:255')]
public string $name;

#[Validate('required|email')]
public string $email;

#[Validate('required|integer|min_value:0')]
public int $quantity;
```

## Required vs Optional

```php
// Required: must be present and not empty
#[Validate('required|string')]
public string $username;

// Optional: may be missing
#[Validate('string')]
public ?string $bio = null;

// Nullable without a default: may be null, absent leaves it uninitialized
#[Validate('nullable|string')]
public ?string $reason;

// Optional with default
#[Validate('integer')]
public int $page = 1;
```

## Absent vs Null vs Empty String

The handler distinguishes three states for each field:

| What frontend sends | Meaning | Behavior |
|---------------------|---------|----------|
| Field absent | Don't touch | Use default value or trigger `required` / `required_*` validation |
| `"name": null` | Clear (null) | Set `null` for nullable types, default for non-nullable |
| `"name": ""` | Clear (empty string) | Preserve `""`, then cast per type |
| `"name": "hello"` | Set value | Normal processing pipeline |

This distinction is important for PATCH-style updates where absent fields should not be modified, `null` explicitly clears a nullable field, and `""` sets an empty string.

### Conditional presence for absent fields

An absent field with a default is normally skipped entirely — nothing to validate. The exception is the conditional-presence family (`required_if`, `required_with`, `required_without_all`, ...): those rules decide whether the field is *allowed to be missing*, so the absence itself is what has to be validated.

```php
#[Validate('in:individual,company')]
public string $company_type = 'individual';

// May be omitted in general — but not when company_type is 'company'.
#[Validate('nullable|string|max:255|required_if:company_type,company')]
public ?string $name = null;
```

When `name` is absent from the payload, the handler still enrols it into validation (with its default — here `null` — as the value), so the validator can fire `required_if` when the condition is met. Fields with a default and **no** `required_*` rule are skipped when absent, exactly as before.

Note that unlike literal `required`, a `required_*` rule combined with a default value is legal — it is the intended usage pattern.

---

## Common Rules

::: info Validator Implementation
Available rules depend on your `ValidatorInterface` implementation. The examples below assume usage with [solophp/validator](https://github.com/SoloPHP/Validator).
:::

| Rule | Description | Example |
|------|-------------|---------|
| `required` | Must be present and not empty | `'required'` |
| `nullable` | Can be `null` | `'nullable'` |
| `string` | Must be a string | `'string'` |
| `integer` | Must be an integer | `'integer'` |
| `numeric` | Must be numeric | `'numeric'` |
| `email` | Must be valid email | `'email'` |
| `boolean` | Must be boolean-like | `'boolean'` |
| `array` | Must be an array | `'array'` |
| `date` | Must be valid date (optionally in a format) | `'date'`, `'date:Y-m-d'` |
| `min:n` | Minimum length | `'min:3'` |
| `max:n` | Maximum length | `'max:255'` |
| `min_value:n` | Minimum numeric value | `'min_value:0'` |
| `max_value:n` | Maximum numeric value | `'max_value:100'` |
| `in:a,b,c` | Must be in list | `'in:active,inactive'` |
| `length:n` | Exact length | `'length:10'` |

::: warning Unknown rules are skipped
solophp/validator silently skips rule names it does not know. Rules such as `unique`, `exists`, `same`, `accepted`, `after` or `url` in the examples on this page are application rules — register them with `addCustomRule()` / `addRule()`, or they validate nothing.
:::

---

## Cross-field references

The handler passes rule strings to `ValidatorInterface::validate()` verbatim — it does not substitute placeholders or otherwise transform them.

Cross-field references in rules (`unique:users,email,{id}`, `exists:…,scope_col,{field}`, `required_if:type,manual`) are the validator's concern. A typical implementation resolves `{id}` / sibling-field references against the validation payload, which contains every field present in the request — including those declared with `#[FromRoute]` and `#[FromContext]`, and those without a `#[Validate]` of their own.

So declare the referenced field as a real property:

```php
use Solo\RequestHandler\Attributes\Source\{FromRoute, FromContext};

class UpdateUserRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer|exists:users,id')]
    public int $id;

    #[FromContext('tenantId')]
    public int $tenantId;

    #[Validate('required|email|unique:users,email,{id},tenant_id,{tenantId}')]
    public string $email;
}

$dto = $handler->handleBody(
    UpdateUserRequest::class,
    $request,
    route:   ['id' => 123],
    context: ['tenantId' => 99],
);
// Validator sees data: ['id' => 123, 'tenantId' => 99, 'email' => '...']
// and resolves {id} / {tenantId} against that payload.
```

If your validator (or custom rule) needs a different convention, that's a validator-side concern — see your validator's docs. See [Route + context arguments](/guide/configuration#route-context-arguments) for how `$route` / `$context` are passed in.

---

## Handling Validation Errors

All errors of a request come back in one `ValidationException`: the validator's errors, cast failures (see [Type Casting](/features/type-casting#cast-failures-are-validation-errors)) and the errors of every nested object and item, in declaration order. Catch it to handle them.

### Error keys follow the input

Errors are keyed by where the client put the value, so a form can map them to its fields without knowing the PHP property names:

| Property | Error key |
|----------|-----------|
| `public string $name` | `name` |
| `#[FromBody('display_name')] public string $name` | `display_name` |
| `#[FromBody('customer.id')] public int $customerId` | `customer.id` |
| `#[FromRoute('product_id')] public int $productId` | `product_id` |
| `#[FromContext('authUserId')] public int $createdBy` | `authUserId` |
| a field of `#[FromBody('order.lines')] #[Items(...)]` | `order.lines.0.quantity` |

Rule strings still reference **property names** (`same:password`, `required_if:company_type,company`, `{id}`): the validator works on the DTO. A `ValidationException` thrown by a handler keyed by a property name is moved to that property's input key too.

```php
use Solo\RequestHandler\Exceptions\ValidationException;

try {
    $dto = $handler->handleBody(CreateUserRequest::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => [['rule' => 'email']],
    //     'password' => [['rule' => 'min', 'params' => ['8']]],
    // ]

    return $this->json(['errors' => $errors], 422);
}
```

---

## Configuration Validation

Invalid configurations are detected at build time:

```php
// ❌ ConfigurationException: nullable rule with non-nullable type
#[Validate('nullable|email')]
public string $email;  // Should be ?string

// ❌ ConfigurationException: required with default value
#[Validate('required|string')]
public string $name = 'default';  // Remove default or 'required'
```

---

## Validator Implementation

The package requires a validator implementing `ValidatorInterface`:

```php
interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, list<array{rule: string, params?: string[]}>>
     */
    public function validate(
        array $data,
        array $rules
    ): array;
}
```

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

---

## Practical Examples

### User Registration

```php
final class RegisterRequest extends Request
{
    #[Validate('required|string|min:3|max:50|alpha_num')]
    public string $username;

    #[Validate('required|email|unique:users,email')]
    public string $email;

    #[Validate('required|string|min:8')]
    #[Exclude]                                       // hide from toArray()
    public string $password;

    #[Validate('required|same:password')]
    #[Exclude]
    public string $passwordConfirmation;

    #[Validate('required|accepted')]
    public bool $termsAccepted;
}
```

### Product Creation

```php
final class CreateProductRequest extends Request
{
    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('required|numeric|min_value:0')]
    public float $price;

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    #[Validate('required|integer|exists:categories,id')]
    public int $categoryId;

    #[Validate('nullable|array')]
    public ?array $tags = null;

    #[Validate('nullable|url')]
    public ?string $imageUrl = null;

    #[Validate('in:draft,published')]
    public string $status = 'draft';
}
```

### Search Filters

```php
final class SearchRequest extends Request
{
    #[Validate('nullable|string|max:100')]
    public ?string $query = null;

    #[Validate('nullable|in:name,price,date')]
    public ?string $sortBy = null;

    #[Validate('nullable|in:asc,desc')]
    public ?string $sortDir = null;

    #[Validate('integer|min_value:1|max_value:100')]
    public int $limit = 20;

    #[Validate('integer|min_value:1')]
    public int $page = 1;

    #[Validate('nullable|date:Y-m-d')]
    public ?string $dateFrom = null;

    #[Validate('nullable|date:Y-m-d|after:dateFrom')]
    public ?string $dateTo = null;
}
```
