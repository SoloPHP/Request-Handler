# Attributes API

13 attribute classes organised into two namespaces. See [Attributes (feature guide)](/features/field-attribute) for usage patterns and examples.

All attributes target `Attribute::TARGET_PROPERTY` and are not repeatable — at most one of each per property.

---

## Source attributes — `Solo\RequestHandler\Attributes\Source\*`

Source attributes are optional. Without one, the source defaults to whatever the `handle*` method dictates (Body for `handleBody`/`handleArray`, Query for `handleQuery`). Declaring more than one source attribute on the same property throws `ConfigurationException::multipleSources`.

### `#[FromRoute(?string $key = null)]`

```php
namespace Solo\RequestHandler\Attributes\Source;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class FromRoute
{
    public function __construct(public ?string $key = null) {}
}
```

Reads from the `$route` argument passed to `handle*()`. Flat keys only — dots are part of the literal key name. Defaults to property name when `null`. Empty-string key throws `InvalidArgumentException`.

### `#[FromContext(string $key)]`

```php
final class FromContext
{
    public function __construct(public string $key) {}
}
```

Reads from the `$context` argument passed to `handle*()`. `$key` is required and non-empty.

### `#[FromBody(string $path)]`

```php
final class FromBody
{
    public function __construct(public string $path) {}
}
```

Reads from the body bag via a dot-path (`'customer.id'`, `'address.city'`). A single-segment path acts as a flat key remap (`#[FromBody('display_name')]` on `public string $name`).

Malformed paths (leading/trailing dot, empty segment like `'a..b'`) throw `InvalidArgumentException`. Resolving the dot-path to an array while the property is a scalar yields a `ValidationException` with `['field' => [['rule' => 'scalar']]]`.

---

## Pipeline attributes — `Solo\RequestHandler\Attributes\*`

### `#[Validate(string $rules)]`

```php
namespace Solo\RequestHandler\Attributes;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Validate
{
    public function __construct(public string $rules) {}
}
```

Rules are passed verbatim to `ValidatorInterface::validate()`. The handler does not transform rule strings; cross-field references (e.g. `unique:users,email,{id}`) are the validator's concern, and a typical implementation resolves `{id}` against the validation payload — which contains every DTO field, including those marked `#[FromRoute]` / `#[FromContext]`. Empty rules throw `InvalidArgumentException`.

### `#[Cast(string $type)]`

```php
final class Cast
{
    public function __construct(public string $type) {}
}
```

Built-in type for `BuiltInCaster`: `int`, `float`, `bool`, `string`, `array`, or a datetime spec (`datetime`, `datetime:immutable`, `datetime:Y-m-d`, `datetime:immutable:Y-m-d H:i:s`).

Passing a class name throws `ConfigurationException::castExpectsBuiltInType` at metadata-build time — use `#[Caster]` for custom logic. Unknown strings throw `ConfigurationException::unknownCastType`. Mutually exclusive with `#[Caster]` and `#[Items]`.

### `#[Caster(class-string<CasterInterface> $class)]`

```php
final class Caster
{
    public function __construct(public string $class) {}
}
```

Custom caster — `$class` must implement `CasterInterface`. Missing or non-implementing class throws `ConfigurationException::invalidCaster`. Mutually exclusive with `#[Cast]` and `#[Items]`.

### `#[PreProcess(string $handler)]`

```php
final class PreProcess
{
    public function __construct(public string $handler) {}
}
```

Handler can be a global function, a `ProcessorInterface`/`CasterInterface` class, or a `public static` method on the Request class (non-static methods throw `ConfigurationException::processorMethodNotStatic`).

Runs before validation. Has no `config` parameter.

### `#[PostProcess(string $handler, array $config = [])]`

```php
final class PostProcess
{
    public function __construct(
        public string $handler,
        /** @var array<string, mixed> */
        public array $config = [],
    ) {}
}
```

Same handler rules as `#[PreProcess]`. `config` is exposed to `ProcessorInterface` implementations via `ProcessContext::$config`. Auto-casting is skipped on properties with `#[PostProcess]`.

### `#[Generator(class-string<GeneratorInterface> $class, array $options = [])]`

```php
final class Generator
{
    public function __construct(
        public string $class,
        /** @var array<string, mixed> */
        public array $options = [],
    ) {}
}
```

`$class` must implement `GeneratorInterface`. The property is **not** read from any source — the generator produces the value. `$options` are passed to `generate()`.

Mutually exclusive with `#[Validate]`, `#[PreProcess]`, `#[PostProcess]`, `#[Cast]`, `#[Caster]`, `#[Items]`, and every source attribute (throws `ConfigurationException::generatorConflict`).

### `#[Items(class-string<Request> $class)]`

```php
final class Items
{
    public function __construct(public string $class) {}
}
```

`$class` must extend `Request`. Property must be typed `array` or `?array`. Each element of the input array is processed through `$class`. Auto-casting is skipped on items properties.

Mutually exclusive with `#[Cast]`, `#[Caster]`, `#[Generator]`. `$route` is NOT propagated into nested items — `$context` IS.

### `#[Group(string $name, ?string $mapTo = null)]`

```php
final class Group
{
    public function __construct(
        public string $name,
        public ?string $mapTo = null,
    ) {}
}
```

Marks the property as a member of group `$name`. `$mapTo` optionally remaps the output key when `Request::group($name)` is built. See [Field Grouping](/features/grouping).

### `#[Exclude]`

```php
final class Exclude {}
```

Property is processed normally but omitted from `Request::toArray()`. `has()` / `get()` / `group()` still work. Use for sensitive fields (passwords, tokens).

### `#[Ignore]`

```php
final class Ignore {}
```

Removes the property from the RequestHandler pipeline entirely — it is NOT read from any source, NOT validated, NOT cast, and NOT included in `toArray()`/`has()`/`get()`/`group()`. Use for public properties that the controller assigns directly (injected services, computed values).

Cannot coexist with any other RequestHandler attribute — throws `ConfigurationException::ignoreConflict` if combined. Visibility-based opt-out (`protected`/`private`) is an equivalent alternative when the property does not need to stay public.

---

## Field source enum — `Solo\RequestHandler\FieldSource`

```php
enum FieldSource
{
    case Body;
    case Query;
    case Route;
    case Context;
}
```

Stored in `PropertyMetadata::$source` and used by `RequestHandler` to dispatch to the correct bag at lookup time.

---

## Composition

Attributes compose freely; a typical "rich" property uses 2–3:

```php
use Solo\RequestHandler\Attributes\{Validate, Cast, PreProcess, PostProcess, Generator, Items, Group, Exclude};
use Solo\RequestHandler\Attributes\Source\{FromRoute, FromContext};

#[FromRoute]
#[Validate('required|integer|exists:products,id')]
public int $id;

#[Validate('required|integer|min:1')]
#[Cast('int')]
public int $quantity;

#[Validate('required|array|min:1')]
#[Items(OrderItemRequest::class)]
public ?array $items = null;

#[Generator(UuidGenerator::class)]
public string $revisionId;

#[Validate('required|string|min:8')]
#[Exclude]
public string $password;

#[FromContext('authUserId')]
#[Validate('required|integer')]
public int $updatedBy;

protected ?User $currentUser = null; // controller sets this — handler ignores
```
