# Validation

Validation rules are declared via the `#[Validate]` attribute.

## Basic Rules

```php
use Solo\RequestHandler\Attributes\Validate;

#[Validate('required|string|max:255')]
public string $name;

#[Validate('required|email')]
public string $email;

#[Validate('required|integer|min:0')]
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

// Required Nullable: must be present but can be null
#[Validate('required|nullable|string')]
public ?string $reason;

// Optional with default
#[Validate('integer')]
public int $page = 1;
```

## Absent vs Null vs Empty String

The handler distinguishes three states for each field:

| What frontend sends | Meaning | Behavior |
|---------------------|---------|----------|
| Field absent | Don't touch | Use default value or trigger required validation |
| `"name": null` | Clear (null) | Set `null` for nullable types, default for non-nullable |
| `"name": ""` | Clear (empty string) | Preserve `""`, then cast per type |
| `"name": "hello"` | Set value | Normal processing pipeline |

This distinction is important for PATCH-style updates where absent fields should not be modified, `null` explicitly clears a nullable field, and `""` sets an empty string.

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
| `date` | Must be valid date | `'date'` |
| `min:n` | Minimum length | `'min:3'` |
| `max:n` | Maximum length | `'max:255'` |
| `min_value:n` | Minimum numeric value | `'min_value:0'` |
| `max_value:n` | Maximum numeric value | `'max_value:100'` |
| `in:a,b,c` | Must be in list | `'in:active,inactive'` |
| `length:n` | Exact length | `'length:10'` |
| `date_format:f` | Must match date format | `'date_format:Y-m-d'` |

---

## Cross-field references

The handler passes rule strings to `ValidatorInterface::validate()` verbatim — it does not substitute placeholders or otherwise transform them.

Cross-field references in rules (`unique:users,email,{id}`, `exists:…,scope_col,{field}`, `required_if:type,manual`) are the validator's concern. A typical implementation resolves `{id}` / sibling-field references against the validation payload, which contains every DTO field — including those declared with `#[FromRoute]` and `#[FromContext]`.

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

Catch `ValidationException` to handle errors:

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

    #[Validate('required|numeric|min:0')]
    public float $price;

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    #[Validate('required|integer|exists:categories,id')]
    public int $categoryId;

    #[Validate('nullable|array')]
    public array $tags = [];

    #[Validate('nullable|url')]
    public ?string $imageUrl = null;

    #[Validate('required|in:draft,published')]
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

    #[Validate('integer|min:1|max:100')]
    public int $limit = 20;

    #[Validate('integer|min:1')]
    public int $page = 1;

    #[Validate('nullable|date_format:Y-m-d')]
    public ?string $dateFrom = null;

    #[Validate('nullable|date_format:Y-m-d|after:dateFrom')]
    public ?string $dateTo = null;
}
```
