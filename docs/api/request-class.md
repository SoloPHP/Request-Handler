# Request Class

The `Request` class is the base class for all request DTOs. Extend it to define your request structures.

## Usage

```php
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Request;

final class CreateUserRequest extends Request
{
    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('required|email')]
    public string $email;

    #[Validate('nullable|string')]
    public ?string $bio = null;
}
```

---

## Methods

### toArray()

Convert all initialized properties to an array. Excludes fields marked with `#[Exclude]`. `protected` and `private` properties are not part of the DTO surface (use those for internal state the handler should not touch).

```php
public function toArray(bool $onlyProvided = false): array
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$onlyProvided` | `bool` | Keep only the properties the request supplied — see [`provided()`](#provided). Applies to nested DTOs and `#[Items]` too |

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

**PATCH:** `toArray(onlyProvided: true)` is the change set — the fields the client sent, without the defaults of the ones it left out:

```php
// PATCH /products/5  {"name": "Desk", "note": null}
$dto = $handler->handleBody(UpdateProductRequest::class, $request, route: ['id' => 5]);

$dto->toArray();                    // ['id' => 5, 'name' => 'Desk', 'note' => null, 'price' => null, ...]
$dto->toArray(onlyProvided: true);  // ['id' => 5, 'name' => 'Desk', 'note' => null]
```

::: warning Overriding toArray()
A subclass that overrides `toArray()` must declare the parameter: `public function toArray(bool $onlyProvided = false): array`.
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

### provided()

Names of the properties the request supplied, in declaration order.

```php
public function provided(): array
```

**Returns:** `list<string>` — every property whose key was present in its source (body, query, route or context), even with an explicit `null` or `""`, and the generated ones. A property left at its default because the client did not send it is not listed, nor one left uninitialised — a post-processor's `null` for a property that can hold neither null nor a default.

A DTO created with `new` (not by `RequestHandler`) has nothing provided, and neither has a clone or an unserialized copy: the list is kept beside the instance the handler built, not in its properties — `(array) $dto`, `serialize()` and normalizers see only the DTO's own fields.

```php
// Body: {"name": "Desk", "note": null}
$dto->provided();   // ['name', 'note']
```

---

### isProvided()

Whether the request supplied the property — see [`provided()`](#provided).

```php
public function isProvided(string $name): bool
```

Unlike `has()`, a default value does not count: with `public ?string $note = null;` absent from the input, `has('note')` is `true` and `isProvided('note')` is `false`.

```php
if ($dto->isProvided('email')) {
    $this->sendVerification($dto->email);   // only when the client changed it
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
- Associative array properties: contents are merged into result
- Scalar properties and sequential arrays: added by property name, or by `mapTo` if specified
- Empty arrays are skipped
- Duplicate `AND`/`OR` keys fold into integer-keyed list entries so multiple logical groups coexist
- Throws `LogicException` on any other duplicate key

**Example:**

```php
final class SearchRequest extends Request
{
    #[Group('filters')]
    public array $search = [];

    #[Group('filters')]
    public ?string $status = null;

    #[Group('pagination')]
    public int $page = 1;
}

$filters = $dto->group('filters');
// ['name' => 'test', 'status' => 'active']

$pagination = $dto->group('pagination');
// ['page' => 1]
```

See [Field Grouping](/features/grouping) for details.

---

### clearCache()

Clear the static metadata cache used by `toArray()`/`has()`/`get()`/`group()`. Primarily a test helper for isolating cases within a single PHP process — under PHP-FPM the cache dies with the request, so production code never needs this.

```php
public static function clearCache(?string $className = null): void
```

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `$className` | `?string` | Clear specific class cache, or all if `null` |

**Example:**

```php
// Clear all cache
Request::clearCache();

// Clear specific class
Request::clearCache(SearchRequest::class);
```

---

## Direct Property Access

Properties can be accessed directly when initialized:

```php
$dto = $handler->handleBody(UserRequest::class, $request);

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
