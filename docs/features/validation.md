# Validation

Validation rules are specified in the `rules` parameter of the `#[Field]` attribute.

## Basic Rules

```php
#[Field(rules: 'required|string|max:255')]
public string $name;

#[Field(rules: 'required|email')]
public string $email;

#[Field(rules: 'required|integer|min:0')]
public int $quantity;
```

## Required vs Optional

```php
// Required: must be present and not empty
#[Field(rules: 'required|string')]
public string $username;

// Optional: may be missing
#[Field(rules: 'string')]
public ?string $bio = null;

// Required Nullable: must be present but can be null
#[Field(rules: 'required|nullable|string')]
public ?string $reason;

// Optional with default
#[Field(rules: 'integer')]
public int $page = 1;
```

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

## Route Parameter Placeholders

Use `{placeholder}` to inject route parameters into rules:

```php
class UpdateUserRequest extends Request
{
    #[Field(rules: 'required|email|unique:users,email,{id}')]
    public string $email;
}

// Handle with route params
$dto = $handler->handle(
    UpdateUserRequest::class,
    $request,
    ['id' => 123]
);
// Rule becomes: 'required|email|unique:users,email,123'
```

Multiple placeholders:

```php
#[Field(rules: 'unique:users,email,{userId},tenant_id,{tenantId}')]
public string $email;

// With ['userId' => 42, 'tenantId' => 99]
// Rule becomes: 'unique:users,email,42,tenant_id,99'
```

---

## Custom Error Messages

Override validation messages by implementing the `messages()` method:

```php
final class RegisterRequest extends Request
{
    #[Field(rules: 'required|string|min:3')]
    public string $username;

    #[Field(rules: 'required|email')]
    public string $email;

    #[Field(rules: 'required|string|min:8')]
    public string $password;

    protected function messages(): array
    {
        return [
            'username.required' => 'Please choose a username',
            'username.min' => 'Username must be at least 3 characters',
            'email.required' => 'We need your email address',
            'email.email' => 'Please enter a valid email',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 8 characters',
        ];
    }
}
```

Message format: `{field}.{rule}`

---

## Handling Validation Errors

Catch `ValidationException` to handle errors:

```php
use Solo\RequestHandler\Exceptions\ValidationException;

try {
    $dto = $handler->handle(RegisterRequest::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => ['Please enter a valid email'],
    //     'password' => ['Password must be at least 8 characters'],
    // ]
    
    return $this->json(['errors' => $errors], 422);
}
```

---

## Configuration Validation

Invalid configurations are detected at build time:

```php
// ❌ ConfigurationException: nullable rule with non-nullable type
#[Field(rules: 'nullable|email')]
public string $email;  // Should be ?string

// ❌ ConfigurationException: required with default value
#[Field(rules: 'required|string')]
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
     * @param array<string, string> $messages
     * @return array<string, array<string>>
     */
    public function validate(
        array $data,
        array $rules,
        array $messages = []
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
    #[Field(rules: 'required|string|min:3|max:50|alpha_num')]
    public string $username;

    #[Field(rules: 'required|email|unique:users,email')]
    public string $email;

    #[Field(rules: 'required|string|min:8')]
    public string $password;

    #[Field(rules: 'required|same:password')]
    public string $passwordConfirmation;

    #[Field(rules: 'required|accepted')]
    public bool $termsAccepted;

    protected function messages(): array
    {
        return [
            'termsAccepted.accepted' => 'You must accept the terms of service',
        ];
    }
}
```

### Product Creation

```php
final class CreateProductRequest extends Request
{
    #[Field(rules: 'required|string|max:255')]
    public string $name;

    #[Field(rules: 'required|numeric|min:0')]
    public float $price;

    #[Field(rules: 'nullable|string|max:1000')]
    public ?string $description = null;

    #[Field(rules: 'required|integer|exists:categories,id')]
    public int $categoryId;

    #[Field(rules: 'nullable|array')]
    public array $tags = [];

    #[Field(rules: 'nullable|url')]
    public ?string $imageUrl = null;

    #[Field(rules: 'required|in:draft,published')]
    public string $status = 'draft';
}
```

### Search Filters

```php
final class SearchRequest extends Request
{
    #[Field(rules: 'nullable|string|max:100')]
    public ?string $query = null;

    #[Field(rules: 'nullable|in:name,price,date')]
    public ?string $sortBy = null;

    #[Field(rules: 'nullable|in:asc,desc')]
    public ?string $sortDir = null;

    #[Field(rules: 'integer|min:1|max:100')]
    public int $limit = 20;

    #[Field(rules: 'integer|min:1')]
    public int $page = 1;

    #[Field(rules: 'nullable|date_format:Y-m-d')]
    public ?string $dateFrom = null;

    #[Field(rules: 'nullable|date_format:Y-m-d|after:dateFrom')]
    public ?string $dateTo = null;
}
```
