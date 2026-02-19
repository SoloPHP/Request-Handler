# Field Grouping

Group related fields together and extract them as a single array using the `group()` method.

## Basic Usage

```php
final class SearchRequest extends Request
{
    #[Field(group: 'criteria')]
    public ?string $search = null;

    #[Field(group: 'criteria')]
    public ?string $status = null;

    #[Field(group: 'pagination')]
    public int $page = 1;

    #[Field(group: 'pagination')]
    public int $perPage = 20;
}

$dto = $handler->handle(SearchRequest::class, $request);

$criteria = $dto->group('criteria');
// ['search' => '...', 'status' => '...']

$pagination = $dto->group('pagination');
// ['page' => 1, 'perPage' => 20]
```

---

## Flattening Behavior

The `group()` method returns a flat array:

- **Array properties**: Contents are merged into result
- **Scalar properties**: Added by property name (or by `mapTo` if specified)

```php
final class FilterRequest extends Request
{
    #[Field(group: 'criteria')]
    public array $search = [];

    #[Field(group: 'criteria')]
    public array $filters = [];

    #[Field(group: 'criteria')]
    public int $limit = 10;
}

// Given:
$dto->search = ['name' => ['LIKE', '%test%']];
$dto->filters = ['status' => 'active'];
$dto->limit = 20;

$criteria = $dto->group('criteria');
// Result:
// [
//     'name' => ['LIKE', '%test%'],  // merged from $search
//     'status' => 'active',           // merged from $filters
//     'limit' => 20                   // scalar by property name
// ]
```

---

## Key Remapping with mapTo

Use `mapTo` to change the output key in `group()` for scalar properties. This is useful when the PHP property name differs from the desired output key (e.g., database column names):

```php
final class FilterRequest extends Request
{
    #[Field(mapTo: 'positions.id', group: 'criteria')]
    public int $position_id;

    #[Field(mapTo: 'departments.name', group: 'criteria')]
    public ?string $department = null;
}

$dto->position_id = 5;
$dto->department = 'Engineering';

$criteria = $dto->group('criteria');
// [
//     'positions.id' => 5,          // mapped from $position_id
//     'departments.name' => 'Engineering'  // mapped from $department
// ]
```

::: info
`mapTo` only affects scalar properties in `group()`. Array properties are always merged by their own keys. `toArray()` is not affected by `mapTo`.
:::

---

## Duplicate Key Protection

A `LogicException` is thrown if duplicate keys are detected:

```php
final class ConflictRequest extends Request
{
    #[Field(group: 'data')]
    public array $first = [];   // ['name' => 'Alice']

    #[Field(group: 'data')]
    public array $second = [];  // ['name' => 'Bob']
}

$dto->group('data');
// LogicException: Duplicate key 'name' in group 'data' from property 'second'
```

---

## Uninitialized Properties

Only initialized properties are included in group results:

```php
final class PartialRequest extends Request
{
    #[Field(group: 'filters')]
    public ?string $search = null;

    #[Field(group: 'filters')]
    public ?string $category; // No default, might be uninitialized
}

// If only 'search' was in request:
$dto->group('filters');
// ['search' => 'test']  // 'category' not included
```

---

## Non-Existent Groups

Returns empty array for groups with no matching fields:

```php
$dto->group('nonexistent');
// []
```

---

## Performance

Group metadata is cached per class. Multiple calls to `group()` reuse cached property lists:

```php
// First call builds cache
$filters = $dto->group('criteria');

// Subsequent calls use cache
$filters = $dto->group('criteria');
```

### Clearing Cache

For long-running processes (Swoole, RoadRunner, Octane):

```php
// Clear all cache
Request::clearGroupCache();

// Clear specific class
Request::clearGroupCache(SearchRequest::class);
```

---

## Practical Examples

### Search Filters

```php
final class ProductSearchRequest extends Request
{
    #[Field(group: 'filters')]
    public ?string $query = null;

    #[Field(group: 'filters')]
    public ?string $category = null;

    #[Field(group: 'filters')]
    public ?float $minPrice = null;

    #[Field(group: 'filters')]
    public ?float $maxPrice = null;

    #[Field(group: 'sorting')]
    public string $sortBy = 'created_at';

    #[Field(group: 'sorting')]
    public string $sortDir = 'DESC';

    #[Field(group: 'pagination')]
    public int $page = 1;

    #[Field(group: 'pagination')]
    public int $limit = 20;
}

// Usage
$filters = $dto->group('filters');
$sorting = $dto->group('sorting');
$pagination = $dto->group('pagination');

$products = $repository->search($filters, $sorting, $pagination);
```

### API Response Options

```php
final class ApiRequest extends Request
{
    #[Field(rules: 'required|integer')]
    public int $resourceId;

    #[Field(group: 'options')]
    public bool $includeRelations = false;

    #[Field(group: 'options')]
    public bool $includeMeta = false;

    #[Field(group: 'options')]
    public ?string $fields = null;
}

$options = $dto->group('options');
// ['includeRelations' => true, 'includeMeta' => false, 'fields' => 'id,name']
```

### Query Builder Integration

```php
final class UserListRequest extends Request
{
    #[Field(group: 'where')]
    public array $filters = [];

    #[Field(group: 'where')]
    public array $search = [];

    #[Field(group: 'order')]
    public string $orderBy = 'id';

    #[Field(group: 'order')]
    public string $orderDir = 'ASC';
}

// Build query
$query = $userRepository->query();

foreach ($dto->group('where') as $column => $value) {
    $query->where($column, $value);
}

$order = $dto->group('order');
$query->orderBy($order['orderBy'], $order['orderDir']);
```

---

## Combining with Other Features

Groups work with all other field features:

```php
final class ComplexRequest extends Request
{
    #[Field(
        rules: 'nullable|string',
        preProcess: 'trim',
        group: 'search'
    )]
    public ?string $query = null;

    #[Field(
        rules: 'in:asc,desc',
        postProcess: 'strtoupper',
        group: 'sorting'
    )]
    public string $direction = 'asc';

    #[Field(
        generator: TimestampGenerator::class,
        group: 'meta',
        exclude: true
    )]
    public int $requestedAt;
}
```
