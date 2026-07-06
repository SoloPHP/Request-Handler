# Field Grouping

Group related fields with `#[Group]` and extract them as a single array using the `group()` method.

## Basic Usage

```php
use Solo\RequestHandler\Attributes\Group;

final class SearchRequest extends Request
{
    #[Group('criteria')]
    public ?string $search = null;

    #[Group('criteria')]
    public ?string $status = null;

    #[Group('pagination')]
    public int $page = 1;

    #[Group('pagination')]
    public int $perPage = 20;
}

$dto = $handler->handleQuery(SearchRequest::class, $request);

$criteria = $dto->group('criteria');
// ['search' => '...', 'status' => '...']

$pagination = $dto->group('pagination');
// ['page' => 1, 'perPage' => 20]
```

---

## Flattening Behavior

The `group()` method returns a flat array:

- **Associative array properties**: Contents are merged into result
- **Scalar properties and sequential arrays**: Added by property name (or by `mapTo` if specified)
- **Empty arrays**: Skipped entirely

```php
final class FilterRequest extends Request
{
    #[Group('criteria')]
    public array $search = [];

    #[Group('criteria')]
    public array $filters = [];

    #[Group('criteria')]
    public int $limit = 10;

    /** @var array<string> */
    #[Group('criteria')]
    public array $statuses = [];
}

// Given:
$dto->search = ['name' => ['LIKE', '%test%']];
$dto->filters = ['status' => 'active'];
$dto->limit = 20;
$dto->statuses = ['pending', 'paid'];

$criteria = $dto->group('criteria');
// Result:
// [
//     'name' => ['LIKE', '%test%'],            // associative array — merged by keys
//     'status' => 'active',                     // associative array — merged by keys
//     'limit' => 20,                            // scalar — by property name
//     'statuses' => ['pending', 'paid'],        // sequential array — by property name
// ]
// Note: empty arrays (e.g. $search = []) are skipped entirely
```

---

## Key Remapping with `mapTo`

Use the `mapTo:` named argument on `#[Group]` to change the output key for scalar properties. Useful when the PHP property name differs from the desired output key (e.g., database column names):

```php
final class FilterRequest extends Request
{
    #[Group('criteria', mapTo: 'positions.id')]
    public int $position_id;

    #[Group('criteria', mapTo: 'departments.name')]
    public ?string $department = null;
}

$dto->position_id = 5;
$dto->department = 'Engineering';

$criteria = $dto->group('criteria');
// [
//     'positions.id' => 5,
//     'departments.name' => 'Engineering'
// ]
```

::: info
`mapTo` only affects scalar properties and sequential arrays in `group()`. Associative array properties are always merged by their own keys. `toArray()` is not affected by `mapTo`.
:::

---

## Duplicate Key Protection

A `LogicException` is thrown if duplicate keys are detected:

```php
final class ConflictRequest extends Request
{
    #[Group('data')]
    public array $first = [];

    #[Group('data')]
    public array $second = [];
}

$dto->first = ['name' => 'Alice'];
$dto->second = ['name' => 'Bob'];

$dto->group('data');
// LogicException: Duplicate key 'name' in group 'data' from property 'second'
```

### Logical Connector Keys (`AND` / `OR`)

The keys `AND` and `OR` are exempt from duplicate protection — they are logical
group wrappers in the criteria DSL, and more than one grouped property may
legitimately contribute one (e.g. a multi-word search and a stock filter both
emitting an `AND` group). On a collision the second group folds into an
integer-keyed list entry, so the criteria builder AND-combines both:

```php
$dto->search = ['AND' => [['OR' => ['name' => ['LIKE', '%foo%']]]]];
$dto->inStock = ['AND' => [['OR' => ['stock' => ['>', 0]]]]];

$dto->group('criteria');
// [
//     'AND' => [['OR' => ['name' => ['LIKE', '%foo%']]]],
//     0 => ['AND' => [['OR' => ['stock' => ['>', 0]]]]],
// ]
```

Duplicate leaf/column keys still throw — that remains a real conflict.

---

## Uninitialized Properties

Only initialized properties are included in group results:

```php
final class PartialRequest extends Request
{
    #[Group('filters')]
    public ?string $search = null;

    #[Group('filters')]
    public ?string $category;
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
$filters = $dto->group('criteria');
$filters = $dto->group('criteria'); // reuses cache
```

### Clearing Cache

`Request::clearCache()` is provided as a test helper for isolating cases within a single PHP process. Under PHP-FPM you don't need to call it — the cache dies with the request.

```php
Request::clearCache();
Request::clearCache(SearchRequest::class);
```

---

## Practical Examples

### Search Filters

```php
final class ProductSearchRequest extends Request
{
    #[Group('filters')]
    public ?string $query = null;

    #[Group('filters')]
    public ?string $category = null;

    #[Group('filters')]
    public ?float $minPrice = null;

    #[Group('filters')]
    public ?float $maxPrice = null;

    #[Group('sorting')]
    public string $sortBy = 'created_at';

    #[Group('sorting')]
    public string $sortDir = 'DESC';

    #[Group('pagination')]
    public int $page = 1;

    #[Group('pagination')]
    public int $limit = 20;
}

$filters = $dto->group('filters');
$sorting = $dto->group('sorting');
$pagination = $dto->group('pagination');

$products = $repository->search($filters, $sorting, $pagination);
```

### API Response Options

```php
final class ApiRequest extends Request
{
    #[Validate('required|integer')]
    public int $resourceId;

    #[Group('options')]
    public bool $includeRelations = false;

    #[Group('options')]
    public bool $includeMeta = false;

    #[Group('options')]
    public ?string $fields = null;
}

$options = $dto->group('options');
// ['includeRelations' => true, 'includeMeta' => false, 'fields' => 'id,name']
```

### Query Builder Integration

```php
final class UserListRequest extends Request
{
    #[Group('where')]
    public array $filters = [];

    #[Group('where')]
    public array $search = [];

    #[Group('order')]
    public string $orderBy = 'id';

    #[Group('order')]
    public string $orderDir = 'ASC';
}

$query = $userRepository->query();

foreach ($dto->group('where') as $column => $value) {
    $query->where($column, $value);
}

$order = $dto->group('order');
$query->orderBy($order['orderBy'], $order['orderDir']);
```

---

## Combining with Other Attributes

Groups compose with the rest of the attribute family:

```php
use Solo\RequestHandler\Attributes\{Validate, PreProcess, PostProcess, Generator, Group, Exclude};

final class ComplexRequest extends Request
{
    #[Validate('nullable|string')]
    #[PreProcess('trim')]
    #[Group('search')]
    public ?string $query = null;

    #[Validate('in:asc,desc')]
    #[PostProcess('strtoupper')]
    #[Group('sorting')]
    public string $direction = 'asc';

    #[Generator(TimestampGenerator::class)]
    #[Group('meta')]
    #[Exclude]
    public int $requestedAt;
}
```
