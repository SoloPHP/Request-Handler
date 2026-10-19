# Schema Export

`RequestHandler::schema()` reflects a request class into a JSON-serializable description of its input contract. It does **not** read or process any HTTP input — it only inspects the class's cached metadata, so it is safe to call anywhere (including outside a request).

The point: ship validation rules to the frontend from a single source of truth instead of duplicating them by hand.

```php
public function schema(string $className): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$className` | `class-string` | A `Request` subclass to describe |

**Returns:** a map of property name → field schema.

## Example

```php
use Solo\RequestHandler\Attributes\Validate;
use Solo\RequestHandler\Attributes\Source\FromRoute;
use Solo\RequestHandler\Request;

final class UpdateProductRequest extends Request
{
    #[FromRoute]
    #[Validate('required|integer')]
    public int $id;

    #[Validate('required|string|max:255')]
    public string $name;

    #[Validate('nullable|in:draft,published')]
    public ?string $status = null;

    #[Validate('nullable|string|required_if:status,published')]
    public ?string $slug = null;

    #[Validate('integer|min_value:1')]
    public int $page = 1;
}

$schema = $handler->schema(UpdateProductRequest::class);
```

`$schema` is:

```php
[
    'id' => [
        'type'       => 'int',
        'nullable'   => false,
        'required'   => true,
        'requiredIf' => null,
        'hasDefault' => false,
        'exclude'    => false,
        'source'     => 'route',
        'rules'      => [
            ['name' => 'required', 'args' => []],
            ['name' => 'integer', 'args' => []],
        ],
    ],
    'name' => [
        'type'       => 'string',
        'nullable'   => false,
        'required'   => true,
        'requiredIf' => null,
        'hasDefault' => false,
        'exclude'    => false,
        'source'     => null,
        'rules'      => [
            ['name' => 'required', 'args' => []],
            ['name' => 'string', 'args' => []],
            ['name' => 'max', 'args' => ['255']],
        ],
    ],
    'status' => [
        'type'       => 'string',
        'nullable'   => true,
        'required'   => false,
        'requiredIf' => null,
        'hasDefault' => true,
        'exclude'    => false,
        'source'     => null,
        'rules'      => [
            ['name' => 'nullable', 'args' => []],
            ['name' => 'in', 'args' => ['draft', 'published']],
        ],
    ],
    'slug' => [
        'type'       => 'string',
        'nullable'   => true,
        'required'   => false,
        'requiredIf' => ['field' => 'status', 'value' => 'published'],
        'hasDefault' => true,
        'exclude'    => false,
        'source'     => null,
        'rules'      => [
            ['name' => 'nullable', 'args' => []],
            ['name' => 'string', 'args' => []],
            ['name' => 'required_if', 'args' => ['status', 'published']],
        ],
    ],
    'page' => [
        'type'       => 'int',
        'nullable'   => false,
        'required'   => false,
        'requiredIf' => null,
        'hasDefault' => true,
        'exclude'    => false,
        'source'     => null,
        'rules'      => [
            ['name' => 'integer', 'args' => []],
            ['name' => 'min_value', 'args' => ['1']],
        ],
    ],
]
```

## Field schema

Each entry describes one property:

| Key | Type | Meaning |
|-----|------|---------|
| `type` | `?string` | Declared property type without the leading `?` (`'int'`, `'string'`, `'array'`, …), or `null` if untyped |
| `nullable` | `bool` | Whether the property accepts `null` |
| `required` | `bool` | The **literal** `required` rule only — see note below |
| `requiredIf` | `array{field, value}\|null` | First well-formed `required_if` rule, as `{field, value}`; `null` otherwise |
| `hasDefault` | `bool` | Whether the property declares a default value |
| `exclude` | `bool` | Whether the field is hidden from `Request::toArray()` (via `#[Exclude]`) |
| `source` | `?string` | `'body'`, `'query'`, `'route'`, `'context'`, or `null` for the handle method's default bag |
| `rules` | `list<array{name, args}>` | Full normalized rule list |

## Notes

### `required` vs `requiredIf`

`required` reflects only the literal `required` rule. A field that is conditionally required via `required_if:other,value` reports `required => false` and surfaces the condition in `requiredIf` instead. The `required_if` rule is also still present in `rules`, so consumers that ignore `requiredIf` lose nothing.

A malformed `required_if` (missing either argument) is treated as absent: `requiredIf` stays `null`, while the raw rule remains in `rules`.

### All managed properties are emitted

Properties are **not** filtered by `exclude` or `source`. Fields marked `#[Exclude]` or sourced via `#[FromRoute]` / `#[FromContext]` still appear — `exclude` and `source` are reported as flags so the consumer decides what to render or submit. (`#[Ignore]` properties are not managed at all and never appear.)

### Rule normalization

Rules are parsed with the same split semantics the handler uses internally:

- the raw string is split on `|`;
- each rule's `name` is the part before the first `:`;
- the remainder, if any, is split on `,` into `args`;
- a rule with no `:` has an empty `args` list.

### JSON-safe by construction

Every emitted value is a scalar or array — no `ReflectionProperty`, enums, objects, or closures leak through — so the result round-trips losslessly through `json_encode` / `json_decode`.

```php
echo json_encode($handler->schema(UpdateProductRequest::class));
```
