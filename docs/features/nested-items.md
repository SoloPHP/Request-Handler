# Nested Objects and Items

A property typed with a `Request` subclass is a **nested object**; `#[Items]` makes an array property a **list** of them. Either way the child DTO runs its full pipeline — read, pre-process, validate, cast, post-process, generate — and its errors come back under the path the client used.

## Nested Objects

No attribute is needed — the type says it:

```php
final class AddressRequest extends Request
{
    #[Validate('required|string')]
    public string $city;

    #[Validate('required|string')]
    public string $zip;
}

final class ShipmentRequest extends Request
{
    #[Validate('required|string')]
    public string $carrier;

    public AddressRequest $address;            // required by type: absent → uninitialised, null → 'required'

    public ?AddressRequest $billing = null;     // optional
}
```

```php
$dto = $handler->handleBody(ShipmentRequest::class, $request);
// {"carrier": "ups", "address": {"city": "Kyiv", "zip": "01001"}}

$dto->address->city;   // 'Kyiv'
$dto->toArray();       // ['carrier' => 'ups', 'address' => ['city' => 'Kyiv', 'zip' => '01001'], 'billing' => null]
```

Errors use the property path: `address.zip` for a field of the nested object, `address` itself (`['rule' => 'array']`) when the value is not an object. A `#[PostProcess]` on the property runs first, so it can turn a JSON string into the object to read. Nested objects can contain `#[Items]` lists and further nested objects (`from.stock.0.quantity`).

The nested class must be concrete; it is validated together with its parent when metadata is built.

A `#[FromContext]` property typed with a Request subclass is not built from input: it receives the object the application passes in `context`, as it is.

## Item Lists

`#[Items]` validates and processes arrays of nested objects through a referenced Request class.

## Basic Usage

Define a child Request DTO and reference it via `#[Items]`:

```php
use Solo\RequestHandler\Attributes\{Validate, Items};
use Solo\RequestHandler\Request;

final class OrderItemRequest extends Request
{
    #[Validate('required|string|max:255')]
    public string $product;

    #[Validate('required|integer|min_value:1')]
    public int $quantity;

    #[Validate('required|numeric|min_value:0')]
    public float $price;
}

final class CreateOrderRequest extends Request
{
    #[Validate('required|string')]
    public string $customer;

    /** @var OrderItemRequest[]|null */
    #[Validate('required|array|min:1')]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;
}
```

Each element in the `items` array goes through the full processing pipeline of the referenced Request class: validation, type casting, pre/post-processing.

## Input Format

```json
{
    "customer": "John Doe",
    "items": [
        {"product": "Widget", "quantity": 3, "price": "9.99"},
        {"product": "Gadget", "quantity": 1, "price": "24.50"}
    ]
}
```

After processing:

```php
$dto = $handler->handleBody(CreateOrderRequest::class, $request);

$dto->items[0]->product;  // 'Widget'
$dto->items[0]->quantity; // 3
$dto->items[0]->price;    // 9.99
$dto->items[1]->product;  // 'Gadget'

$dto->toArray();
// ['customer' => 'John Doe', 'items' => [
//     ['product' => 'Widget', 'quantity' => 3, 'price' => 9.99],
//     ['product' => 'Gadget', 'quantity' => 1, 'price' => 24.50],
// ]]
```

## Validation Errors

Validation errors from nested items use dot-notation with the item index:

```php
try {
    $dto = $handler->handleBody(CreateOrderRequest::class, $request);
} catch (ValidationException $e) {
    $e->getErrors();
    // [
    //     'items.0.product' => [['rule' => 'required']],
    //     'items.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
    // ]
}
```

Everything is validated before throwing: the parent's own fields, every item, and every `#[Items]` property. A single `ValidationException` carries all errors, in declaration order — each items property is followed by its nested errors:

```php
$e->getErrors();
// [
//     'customer'         => [['rule' => 'required']],
//     'items.0.product'  => [['rule' => 'required']],
//     'items.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
// ]
```

Generators and post-processors of the parent and of the items run only after the whole tree has passed. When a post-processor produces the items array (e.g. decodes a JSON string), the items are validated after it runs — their errors are reported once the rest of the input is valid.

The item class is validated together with its parent when metadata is built: a misconfigured item class fails the parent's first `handle*()` or `schema()` call even if the request carries no items. Self-referencing trees (`#[Items(CategoryRequest::class)]` inside `CategoryRequest`) are supported.

Non-array elements produce a dedicated error:

```php
// Input: {"items": ["not-an-object", {"product": "Widget", ...}]}

$e->getErrors();
// [
//     'items.0' => [['rule' => 'array']],
// ]
```

## Nested Features

Child Request classes support the full attribute set:

```php
use Solo\RequestHandler\Attributes\{Validate, Cast, PostProcess, Generator, Group};
use Solo\RequestHandler\Attributes\Source\FromContext;

final class OrderItemRequest extends Request
{
    #[Generator(UuidGenerator::class)]
    public string $id;

    #[Validate('required|string|max:255')]
    public string $product;

    #[Validate('required|integer|min_value:1')]
    public int $quantity;

    #[Validate('required|numeric|min_value:0')]
    #[PostProcess(RoundCents::class)]
    public float $price;

    #[FromContext('tenantId')]
    public int $tenantId;

    #[Group('pricing')]
    public ?float $discount = null;
}
```

## Route and context in nested items

Route params are **NOT propagated to nested items**. They describe the outer resource (e.g. an order id), not each item. Merging them into item data would silently overwrite item-level fields whose name happened to match a route key (e.g. each item's `id` becoming the outer route's `id`).

`$context` IS propagated, so items can read it via `#[FromContext]` and item processors can access `ProcessContext::$context`.

```php
final class LineItemRequest extends Request
{
    #[FromContext('tenantId')]
    public int $tenantId;

    #[Validate('required|integer|exists:products,id')]
    public int $productId;

    #[Validate('required|integer|min_value:1')]
    public int $quantity;
}

$dto = $handler->handleBody(
    OrderRequest::class,
    $request,
    route:   ['orderId' => 7],          // not visible inside items
    context: ['tenantId' => 42],        // each item picks it up via #[FromContext]
);
```

## handleArray()

For testing or manual processing, use `handleArray()` to process raw data without a PSR-7 request:

```php
$item = $handler->handleArray(OrderItemRequest::class, [
    'product' => 'Widget',
    'quantity' => '3',
    'price' => '9.99',
]);

$item->product;  // 'Widget'
$item->quantity; // 3 (int)
$item->price;    // 9.99 (float)
```

## Processing Pipeline

For fields with `#[Items]`, the processing order is:

1. Parent field is validated (e.g., `required|array|min:1`)
2. Auto-casting is **skipped** — the array is passed directly to items processing
3. Each element goes through the child Request pipeline:
   - Generate values (`#[Generator]`)
   - Pre-process (`#[PreProcess]`)
   - Validate (`#[Validate]`)
   - Cast types
   - Post-process (`#[PostProcess]`)
4. Each processed item is returned as a Request object

::: warning
Auto-casting is intentionally skipped for `#[Items]` fields. The `BuiltInCaster` won't process the array before items are handled. This prevents data corruption when the array contains structured objects.
:::

## Configuration Validation

Invalid configurations throw `ConfigurationException` at build time:

| Error | Cause | Fix |
|-------|-------|-----|
| Class does not exist | `#[Items('NonExistent')]` | Check class name and imports |
| Must extend Request | `#[Items(SomeClass::class)]` (not a Request) | Extend `Request` base class |
| Requires array type | `#[Items]` on `string` property | Use `array` or `?array` type |
| Items with generator | Both `#[Items]` and `#[Generator]` on the same property | Remove one — they are mutually exclusive |

## Practical Examples

### E-commerce Order

```php
final class AddressRequest extends Request
{
    #[Validate('required|string|max:255')]
    public string $street;

    #[Validate('required|string|max:100')]
    public string $city;

    #[Validate('required|string|size:2')]
    public string $country;

    #[Validate('required|string|max:20')]
    public string $zip;
}

final class OrderItemRequest extends Request
{
    #[Validate('required|integer|exists:products,id')]
    public int $productId;

    #[Validate('required|integer|min_value:1|max_value:99')]
    public int $quantity;
}

final class CreateOrderRequest extends Request
{
    #[Generator(UuidGenerator::class)]
    public string $id;

    #[Validate('required|array|min:1')]
    #[Items(OrderItemRequest::class)]
    public ?array $items = null;

    #[Validate('required|array')]
    #[Items(AddressRequest::class)]
    public ?array $addresses = null;

    #[Validate('nullable|string|max:500')]
    public ?string $notes = null;
}
```

### Survey with Questions

```php
final class AnswerRequest extends Request
{
    #[Validate('required|integer|exists:questions,id')]
    public int $questionId;

    #[Validate('required|string|max:1000')]
    public string $value;
}

final class SubmitSurveyRequest extends Request
{
    #[Validate('required|integer|exists:surveys,id')]
    public int $surveyId;

    #[Validate('required|array|min:1')]
    #[Items(AnswerRequest::class)]
    public ?array $answers = null;
}
```
