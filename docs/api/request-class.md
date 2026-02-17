# Request Class

The `Request` class is the base class for all request DTOs. Extend it to define your request structures.

## Usage

```php
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Request;

final class CreateUserRequest extends Request
{
    #[Field(rules: 'required|string|max:255')]
    public string $name;

    #[Field(rules: 'required|email')]
    public string $email;

    #[Field(rules: 'nullable|string')]
    public ?string $bio = null;
}
```

---

## Methods

### toArray()

Convert all initialized properties to an array. Excludes fields with `exclude: true`.

```php
public function toArray(): array
```

**Returns:** Associative array of property names to values.

**Example:**

```php
$dto->name = 'John';
$dto->email = 'john@example.com';

$data = $dto->toArray();
// ['name' => 'John', 'email' => 'john@example.com']
```

::: info Uninitialized Properties
Properties that were not in the request (and have no default value) are not included in the result.
:::

---

### has()

Check if a property is initialized (was present in the request or has a default value).

```php
public function has(string $name): bool
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$name` | `string` | Property name to check |

**Returns:** `true` if property is initialized, `false` otherwise.

**Example:**

```php
if ($dto->has('bio')) {
    echo $dto->bio;
}
```

---

### get()

Get property value with optional default for uninitialized properties.

```php
public function get(string $name, mixed $default = null): mixed
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$name` | `string` | Property name |
| `$default` | `mixed` | Default value if not initialized |

**Returns:** Property value or default.

**Example:**

```php
$bio = $dto->get('bio', 'No bio provided');
```

---

### group()

Get all fields belonging to a specific group as a flat array.

```php
public function group(string $groupName): array
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$groupName` | `string` | Name of the group to extract |

**Returns:** Flat array of grouped field values.

**Behavior:**
- Array properties: contents are merged into result
- Scalar properties: added by property name
- Throws `LogicException` on duplicate keys

**Example:**

```php
final class SearchRequest extends Request
{
    #[Field(group: 'filters')]
    public array $search = [];

    #[Field(group: 'filters')]
    public ?string $status = null;

    #[Field(group: 'pagination')]
    public int $page = 1;
}

$filters = $dto->group('filters');
// ['name' => 'test', 'status' => 'active']

$pagination = $dto->group('pagination');
// ['page' => 1]
```

See [Field Grouping](/features/grouping) for details.

---

### clearGroupCache()

Clear the static group cache. Useful for long-running processes.

```php
public static function clearGroupCache(?string $className = null): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$className` | `?string` | Clear specific class cache, or all if `null` |

**Example:**

```php
// Clear all cache
Request::clearGroupCache();

// Clear specific class
Request::clearGroupCache(SearchRequest::class);
```

---

## Direct Property Access

Properties can be accessed directly when initialized:

```php
$dto = $handler->handle(UserRequest::class, $request);

echo $dto->name;   // Works if 'name' was in request
echo $dto->email;  // Works if 'email' was in request
```

::: danger Uninitialized Access
Accessing an uninitialized property throws a PHP Error:

```php
// If 'bio' was not in request and has no default:
echo $dto->bio; // Error: must not be accessed before initialization

// Use has() or get() instead:
if ($dto->has('bio')) {
    echo $dto->bio;
}
// Or:
echo $dto->get('bio', 'Default value');
```
:::

---

## Best Practices

### Always Use Defaults for Optional Fields

```php
// ✅ Good: optional with default
public ?string $bio = null;
public int $page = 1;

// ❌ Bad: optional without default (requires has() check)
public ?string $bio;
```

### Use Strict Types

```php
<?php

declare(strict_types=1);

final class MyRequest extends Request
{
    // ...
}
```

### Keep DTOs Final

```php
// ✅ Recommended
final class CreateUserRequest extends Request {}

// ❌ Avoid inheritance chains
class BaseUserRequest extends Request {}
class CreateUserRequest extends BaseUserRequest {}
```
