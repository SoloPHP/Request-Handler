# Nested Items

The `items` parameter allows you to validate and process arrays of nested objects through a referenced Request class.

## Basic Usage

Define a child Request DTO and reference it via `items`:

```php
use Solo\RequestHandler\Attributes\Field;
use Solo\RequestHandler\Request;

final class OrderItemRequest extends Request
{
    #[Field(rules: 'required|string|max:255')]
    public string $product;

    #[Field(rules: 'required|integer|min:1')]
    public int $quantity;

    #[Field(rules: 'required|numeric|min:0')]
    public float $price;
}

final class CreateOrderRequest extends Request
{
    #[Field(rules: 'required|string')]
    public string $customer;

    /** @var array<int, array<string, mixed>>|null */
    #[Field(rules: 'required|array|min:1', items: OrderItemRequest::class)]
    public ?array $items = null;
}
```

Each element in the `items` array goes through the full processing pipeline of the referenced Request class: validation, type casting, pre/post-processing.

## Input Format

The input data should contain an array of objects:

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
$dto = $handler->handle(CreateOrderRequest::class, $request);

$dto->items;
// [
//     ['product' => 'Widget', 'quantity' => 3, 'price' => 9.99],
//     ['product' => 'Gadget', 'quantity' => 1, 'price' => 24.50],
// ]
```

Each item is returned as an associative array (result of `toArray()` on the child Request).

## Validation Errors

Validation errors from nested items use dot-notation with the item index:

```php
try {
    $dto = $handler->handle(CreateOrderRequest::class, $request);
} catch (ValidationException $e) {
    $e->getErrors();
    // [
    //     'items.0.product' => ['Product is required'],
    //     'items.1.quantity' => ['Quantity must be at least 1'],
    // ]
}
```

All items are validated before throwing — you get errors for every invalid item in a single exception.

Non-array elements produce a dedicated error:

```php
// Input: {"items": ["not-an-object", {"product": "Widget", ...}]}

$e->getErrors();
// [
//     'items.0' => ['Must be an object'],
// ]
```

## Nested Features

Child Request classes support the full feature set:

```php
final class OrderItemRequest extends Request
{
    // Generators
    #[Field(generator: UuidGenerator::class)]
    public string $id;

    // Validation + Type casting
    #[Field(rules: 'required|string|max:255')]
    public string $product;

    #[Field(rules: 'required|integer|min:1')]
    public int $quantity;

    // Pre/Post processing
    #[Field(rules: 'required|numeric|min:0', postProcess: RoundCents::class)]
    public float $price;

    // Nested mapping
    #[Field(mapFrom: 'meta.sku')]
    public ?string $sku = null;

    // Groups
    #[Field(group: 'pricing')]
    public ?float $discount = null;

    // Custom messages
    protected function messages(): array
    {
        return [
            'product.required' => 'Each item must have a product name',
            'quantity.min' => 'Quantity must be positive',
        ];
    }
}
```

## Route Parameters

Route parameters are propagated to nested items. This allows placeholder replacement in child validation rules:

```php
final class LineItemRequest extends Request
{
    #[Field(rules: 'required|integer|exists:products,id,tenant_id,{tenantId}')]
    public int $productId;
}

// Route params are passed through to nested items
$dto = $handler->handle(
    OrderRequest::class,
    $request,
    ['tenantId' => 42]
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

For fields with `items`, the processing order is:

1. Parent field is validated (e.g., `required|array|min:1`)
2. Auto-casting is **skipped** — the array is passed directly to items processing
3. Each element goes through the child Request pipeline:
   - Generate values (generators)
   - Pre-process
   - Validate
   - Cast types
   - Post-process
4. Each processed item is converted to an array via `toArray()`

::: warning
Auto-casting is intentionally skipped for `items` fields. The `BuiltInCaster` won't process the array before items are handled. This prevents data corruption when the array contains structured objects.
:::

## Configuration Validation

Invalid configurations throw `ConfigurationException` at build time:

| Error | Cause | Fix |
|-------|-------|-----|
| Class does not exist | `items: 'NonExistent'` | Check class name and imports |
| Must extend Request | `items: SomeClass::class` (not a Request) | Extend `Request` base class |
| Requires array type | `items` on `string` property | Use `array` or `?array` type |
| Items with generator | Both `items` and `generator` set | Remove one — they are mutually exclusive |

## Practical Examples

### E-commerce Order

```php
final class AddressRequest extends Request
{
    #[Field(rules: 'required|string|max:255')]
    public string $street;

    #[Field(rules: 'required|string|max:100')]
    public string $city;

    #[Field(rules: 'required|string|size:2')]
    public string $country;

    #[Field(rules: 'required|string|max:20')]
    public string $zip;
}

final class OrderItemRequest extends Request
{
    #[Field(rules: 'required|integer|exists:products,id')]
    public int $productId;

    #[Field(rules: 'required|integer|min:1|max:99')]
    public int $quantity;
}

final class CreateOrderRequest extends Request
{
    #[Field(generator: UuidGenerator::class)]
    public string $id;

    #[Field(rules: 'required|array|min:1', items: OrderItemRequest::class)]
    public ?array $items = null;

    #[Field(rules: 'required|array', items: AddressRequest::class)]
    public ?array $addresses = null;

    #[Field(rules: 'nullable|string|max:500')]
    public ?string $notes = null;
}
```

### Survey with Questions

```php
final class AnswerRequest extends Request
{
    #[Field(rules: 'required|integer|exists:questions,id')]
    public int $questionId;

    #[Field(rules: 'required|string|max:1000')]
    public string $value;
}

final class SubmitSurveyRequest extends Request
{
    #[Field(rules: 'required|integer|exists:surveys,id')]
    public int $surveyId;

    #[Field(rules: 'required|array|min:1', items: AnswerRequest::class)]
    public ?array $answers = null;
}
```
