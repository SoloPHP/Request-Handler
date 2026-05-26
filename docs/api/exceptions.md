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
public function getErrors(): array<string, list<array{rule: string, params?: string[]}>>
```

**Example:**

```php
use Solo\RequestHandler\Exceptions\ValidationException;

try {
    $dto = $handler->handleBody(UserRequest::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => [['rule' => 'required'], ['rule' => 'email']],
    //     'age' => [['rule' => 'min', 'params' => ['18']]],
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
            $dto = $this->handler->handleBody(CreateUserRequest::class, $request);
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

Thrown when a Request class has invalid configuration. This is a **developer error** caught at metadata-build time — before any input is processed — so misconfigurations surface in dev/test, not on a live request.

```php
namespace Solo\RequestHandler\Exceptions;

final class ConfigurationException extends Exception { /* 18 named factories */ }
```

### Error types

Each factory below corresponds to one diagnosable misconfiguration. The message always names the offending `Class::$property` and points at the fix.

#### nullableRuleWithNonNullableType

```php
// ❌ 'nullable' rule on non-nullable type
#[Validate('nullable|email')]
public string $email;

// ✅ Make the type nullable
#[Validate('nullable|email')]
public ?string $email = null;
```

#### castTypeMismatch

```php
// ❌ Cast produces a different type than the property accepts
#[Cast('string')]
public int $id;

// ✅
#[Cast('int')]
public int $id;
```

#### requiredWithDefault

```php
// ❌ 'required' and a default contradict each other
#[Validate('required|string')]
public string $name = 'default';

// ✅
#[Validate('required|string')]
public string $name;
```

#### castAndCasterConflict

```php
// ❌ Both #[Cast] and #[Caster] on one property
#[Cast('int')]
#[Caster(MyCaster::class)]
public int $value;

// ✅ Pick one
#[Caster(MyCaster::class)]
public int $value;
```

#### unknownCastType

```php
// ❌ Not a recognised built-in
#[Cast('unknown_type')]
public string $value;

// ✅
#[Cast('string')]
public string $value;
```

#### castExpectsBuiltInType

```php
// ❌ #[Cast] received a class name
#[Cast(MyCaster::class)]
public string $value;

// ✅ For custom casters, use #[Caster]
#[Caster(MyCaster::class)]
public string $value;
```

#### invalidCaster

```php
// ❌ Class missing or not a CasterInterface
#[Caster(NotACaster::class)]
public string $value;

// ✅
#[Caster(MyCaster::class)]
public string $value;
```

#### invalidProcessor

```php
// ❌ Handler is not a function, class, or static method
#[PreProcess('nonExistentFunction')]
public string $name;

// ✅
#[PreProcess('trim')]
public string $name;
```

#### ambiguousProcessor

Handler name resolves both as a global function AND as a static method on the Request — dispatch is undefined.

```php
// ❌ 'trim' is a built-in AND a static method on this class
final class MyRequest extends Request
{
    #[PreProcess('trim')]
    public string $value;

    public static function trim(string $v): string { return \trim($v, '/'); }
}

// ✅ Rename the method (or pass the class-string of a ProcessorInterface)
```

#### processorMethodNotStatic

```php
// ❌ Handler is an instance method
#[PreProcess('normalize')]
public string $value;

public function normalize(string $v): string { /* ... */ }

// ✅ Make it static (or extract to a ProcessorInterface)
public static function normalize(string $v): string { /* ... */ }
```

#### invalidItems

```php
// ❌ Class missing or not a subclass of Request
#[Items('NonExistentClass')]
public ?array $items = null;

// ✅
#[Items(OrderItemRequest::class)]
public ?array $items = null;
```

#### itemsRequiresArrayType

```php
// ❌ #[Items] on a non-array property
#[Items(OrderItemRequest::class)]
public string $items;

// ✅
#[Items(OrderItemRequest::class)]
public ?array $items = null;
```

#### itemsCastConflict

`#[Items]` skips automatic casting (each element is processed through its own DTO), so `#[Cast]` / `#[Caster]` would have no effect.

```php
// ❌
#[Items(OrderItemRequest::class)]
#[Cast('array')]
public array $items;

// ✅
#[Items(OrderItemRequest::class)]
public array $items = [];
```

#### invalidGenerator

```php
// ❌ Class missing or not a GeneratorInterface
#[Generator(NotAGenerator::class)]
public string $id;

// ✅
#[Generator(UuidGenerator::class)]
public string $id;
```

#### generatorConflict

Generated values bypass the input/validation pipeline, so any companion attribute (`#[Validate]`, `#[Cast]`, `#[FromX]`, `#[Items]`, …) would never run.

```php
// ❌
#[Generator(UuidGenerator::class)]
#[Validate('required|string')]
public string $id;

// ✅ Use one or the other
#[Generator(UuidGenerator::class)]
public string $id;
```

#### multipleSources

```php
// ❌ Two source attributes on one property
#[FromRoute]
#[FromContext('value')]
public string $value;

// ✅ Pick one
#[FromRoute]
public string $value;
```

#### ignoreConflict

`#[Ignore]` removes the property from the pipeline entirely, so any other RequestHandler attribute on the same property would silently never run.

```php
// ❌
#[Ignore]
#[Validate('required|string')]
public string $value;

// ✅ Pick one
#[Validate('required|string')]
public string $value;
```

#### abstractClass

`#[Items]` and `#[Generator]` need a concrete instantiable class.

```php
// ❌
#[Items(AbstractItemRequest::class)]
public ?array $items = null;

// ✅ Pass a concrete subclass
#[Items(OrderItemRequest::class)]
public ?array $items = null;
```

### Usage Pattern

```php
try {
    $dto = $handler->handleBody(BrokenRequest::class, $request);
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
    #[Validate('required|integer')]
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
    $dto = $handler->handleBody(DeleteUserRequest::class, $request);
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
    $dto = $handler->handleBody(MyRequest::class, $request);
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
$dto = $handler->handleBody(MyRequest::class, $request);

// In production - only catch ValidationException
try {
    $dto = $handler->handleBody(MyRequest::class, $request);
} catch (ValidationException $e) {
    return $this->json(['errors' => $e->getErrors()], 422);
}
// ConfigurationException will trigger error handler → 500
```
