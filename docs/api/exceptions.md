# Exceptions

The library provides three exception types for different error scenarios.

## ValidationException

Thrown when request data fails validation.

```php
namespace Solo\RequestHandler\Exceptions;

final class ValidationException extends Exception
{
    public function __construct(array $errors = [], ?Exception $previous = null);
    public function getErrors(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$message` | `string` | `"Validation failed: field1, field2"` |
| `$code` | `int` | `422` |

### Methods

#### getErrors()

Returns validation errors grouped by field.

```php
public function getErrors(): array<string, array<string>>
```

**Example:**

```php
use Solo\RequestHandler\Exceptions\ValidationException;

try {
    $dto = $handler->handle(UserRequest::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => ['The email field is required', 'Invalid email format'],
    //     'age' => ['Must be at least 18'],
    // ]

    return $this->json(['errors' => $errors], 422);
}
```

### Usage Pattern

```php
class UserController
{
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $dto = $this->handler->handle(CreateUserRequest::class, $request);
            $this->userService->create($dto);
            return $this->json(['status' => 'created'], 201);

        } catch (ValidationException $e) {
            // Client error - invalid input
            return $this->json([
                'message' => 'Validation failed',
                'errors' => $e->getErrors()
            ], 422);
        }
    }
}
```

---

## ConfigurationException

Thrown when a Request class has invalid configuration. This is a **developer error** caught at build time.

```php
namespace Solo\RequestHandler\Exceptions;

final class ConfigurationException extends Exception
{
    public static function nullableRuleWithNonNullableType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self;

    public static function castTypeMismatch(
        string $className,
        string $propertyName,
        string $castType,
        string $propertyType
    ): self;

    public static function requiredWithDefault(
        string $className,
        string $propertyName
    ): self;

    public static function invalidProcessor(
        string $className,
        string $propertyName,
        string $processorType,
        string $processorName
    ): self;

    public static function invalidGenerator(
        string $className,
        string $propertyName,
        string $generatorClass,
        string $reason
    ): self;

    public static function invalidItems(
        string $className,
        string $propertyName,
        string $itemsClass,
        string $reason
    ): self;

    public static function itemsRequiresArrayType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self;

    public static function itemsWithGenerator(
        string $className,
        string $propertyName
    ): self;
}
```

### Error Types

#### nullableRuleWithNonNullableType

```php
// ❌ Wrong: 'nullable' rule on non-nullable type
#[Field(rules: 'nullable|email')]
public string $email;

// ✅ Correct: type allows null
#[Field(rules: 'nullable|email')]
public ?string $email = null;
```

#### castTypeMismatch

```php
// ❌ Wrong: casting to string but property is int
#[Field(cast: 'string')]
public int $id;

// ✅ Correct: types match
#[Field(cast: 'int')]
public int $id;
```

#### requiredWithDefault

```php
// ❌ Wrong: required with default value
#[Field(rules: 'required|string')]
public string $name = 'default';

// ✅ Correct: no default for required
#[Field(rules: 'required|string')]
public string $name;
```

#### invalidProcessor

```php
// ❌ Wrong: function doesn't exist
#[Field(preProcess: 'nonExistentFunction')]
public string $name;

// ✅ Correct: valid function or class
#[Field(preProcess: 'trim')]
public string $name;
```

#### invalidGenerator

```php
// ❌ Wrong: class doesn't implement GeneratorInterface
#[Field(generator: NotAGenerator::class)]
public string $id;

// ✅ Correct: implements GeneratorInterface
#[Field(generator: UuidGenerator::class)]
public string $id;
```

#### invalidItems

```php
// ❌ Wrong: class doesn't exist or doesn't extend Request
#[Field(items: 'NonExistentClass')]
public ?array $items = null;

// ✅ Correct: valid Request subclass
#[Field(items: OrderItemRequest::class)]
public ?array $items = null;
```

#### itemsRequiresArrayType

```php
// ❌ Wrong: items on non-array type
#[Field(items: OrderItemRequest::class)]
public string $items;

// ✅ Correct: array type
#[Field(items: OrderItemRequest::class)]
public ?array $items = null;
```

#### itemsWithGenerator

```php
// ❌ Wrong: items and generator are mutually exclusive
#[Field(generator: UuidGenerator::class, items: OrderItemRequest::class)]
public ?array $items = null;

// ✅ Correct: use one or the other
#[Field(items: OrderItemRequest::class)]
public ?array $items = null;
```

### Usage Pattern

```php
try {
    $dto = $handler->handle(BrokenRequest::class, $request);
} catch (ConfigurationException $e) {
    // Developer error - fix the Request class
    error_log('Configuration error: ' . $e->getMessage());
    return $this->json(['error' => 'Internal Server Error'], 500);
} catch (ValidationException $e) {
    // Client error - invalid input
    return $this->json(['errors' => $e->getErrors()], 422);
}
```

---

## AuthorizationException

Available for custom authorization logic. Not thrown by the library itself.

```php
namespace Solo\RequestHandler\Exceptions;

final class AuthorizationException extends Exception
{
    public function __construct(
        string $message = "Access denied",
        int $code = 403,
        ?Exception $previous = null
    );
}
```

### Usage Example

```php
final class DeleteUserRequest extends Request
{
    #[Field(rules: 'required|integer')]
    public int $userId;

    public function authorize(User $currentUser): void
    {
        if (!$currentUser->isAdmin()) {
            throw new AuthorizationException('Only admins can delete users');
        }
    }
}

// In controller
try {
    $dto = $handler->handle(DeleteUserRequest::class, $request);
    $dto->authorize($currentUser);
    // ...
} catch (AuthorizationException $e) {
    return $this->json(['error' => $e->getMessage()], 403);
}
```

---

## Exception Hierarchy

```
Exception
├── ValidationException      (422 - Client error, invalid input)
├── ConfigurationException   (500 - Developer error, fix DTO)
└── AuthorizationException   (403 - Access denied)
```

---

## Best Practices

### Catch Specific Exceptions

```php
try {
    $dto = $handler->handle(MyRequest::class, $request);
    $this->service->process($dto);
    return $this->json(['status' => 'ok']);

} catch (ValidationException $e) {
    // 422 - Tell client what's wrong
    return $this->json(['errors' => $e->getErrors()], 422);

} catch (ConfigurationException $e) {
    // 500 - Log and hide details from client
    $this->logger->error('DTO configuration error', [
        'exception' => $e->getMessage()
    ]);
    return $this->json(['error' => 'Internal error'], 500);
}
```

### Don't Catch ConfigurationException in Production

Configuration errors should be caught during development/testing:

```php
// In development - let it bubble up
$dto = $handler->handle(MyRequest::class, $request);

// In production - only catch ValidationException
try {
    $dto = $handler->handle(MyRequest::class, $request);
} catch (ValidationException $e) {
    return $this->json(['errors' => $e->getErrors()], 422);
}
// ConfigurationException will trigger error handler → 500
```
