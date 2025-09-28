# Request Handler 🛡️

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)

**Robust request validation & authorization layer for HTTP inputs with type-safe handlers and modern PHP 8+ architecture**

## How It Works

Fields are conditionally processed based on their presence in requests and default values:
- **Present in request** → Always included in results
- **Missing + has `default()`** → Included with default value
- **Missing + no default** → Excluded from results entirely
- **Validation** → Only runs on present fields or those marked as `required`

---

## ✨ Features

- **Smart field inclusion** - only processes relevant fields
- **Type-safe processing** with readonly properties and strict types
- **Multi-stage pipeline** (`extract` → `authorize` → `preprocess` → `validate` → `postprocess`)
- **Field mapping** from nested structures via dot notation
- **Vendor-independent validation** - use any validator
- **PSR-7 compatible** HTTP message interface
- **Built-in authorization** with simple overrides

---

## 🔗 Dependencies

- [PSR-7 HTTP Message Interface](https://github.com/php-fig/http-message) (`psr/http-message` ^2.0)
- Any validator implementing `Solo\Contracts\Validator\ValidatorInterface`

### Suggested Validators
- [Solo Validator](https://github.com/solophp/validator) (`solophp/validator`) - Direct compatibility
---

## 📥 Installation

```bash
composer require solophp/request-handler
```

---

## 🚀 Quick Start

### Define a Request Handler

```php
<?php declare(strict_types=1);

namespace App\Requests;

use Solo\RequestHandler\AbstractRequestHandler;
use Solo\RequestHandler\Field;

final class CreateArticleRequest extends AbstractRequestHandler 
{
    protected function fields(): array 
    {
        return [
            Field::for('author_email')
                ->mapFrom('meta.author.email')
                ->validate('required|email'),
                
            Field::for('title')
                ->validate('required|string|max:100')
                ->preprocess(fn(mixed $value): string => trim((string)$value)),
            
            Field::for('status')
                ->default('draft')
                ->validate('string|in:draft,published')
                ->postprocess(fn(mixed $value): string => strtoupper((string)$value))
        ];
    }

    protected function authorize(): bool 
    {
        return $this->user()->can('create', Article::class);
    }
}
```

---

### Handle in Controller

```php
<?php declare(strict_types=1);

namespace App\Controllers;

use App\Requests\CreateArticleRequest;
use Solo\RequestHandler\Exceptions\{ValidationException, AuthorizationException};

final class ArticleController 
{
    public function store(ServerRequestInterface $request, CreateArticleRequest $articleRequest): array
    {
        try {
            $data = $articleRequest->handle($request);
            Article::create($data);
            return ['success' => true, 'data' => $data];
        } catch (ValidationException $e) {
            return ['errors' => $e->getErrors()];
        } catch (AuthorizationException $e) {
            return ['message' => $e->getMessage(), 'code' => 403];
        }
    }
}
```

---

## ⚙️ Field Configuration

| Method                | Required? | Description                                      |
|-----------------------|-----------|--------------------------------------------------|
| `Field::for(string)`  | **Yes**   | Starts field definition                          |
| `mapFrom(string)`     | No        | Map input from custom name/nested path          |
| `default(mixed)`      | No        | Fallback value if field is missing              |
| `validate(string)`    | No        | Validation rules (e.g., `required|string|max:5`) |
| `preprocess(callable)`| No        | Transform raw input **before validation**       |
| `postprocess(callable)`| No       | Modify value **after validation**               |
| `hasDefault()`        | No        | Check if field has explicit default value       |  

### Processing Pipeline

1. **Extract Data** - Merge POST body and GET parameters (body priority)
2. **Authorize** - Check user permissions via `authorize()` method
3. **Map Input** - Resolve values using `mapFrom` paths with dot notation
4. **Preprocess** - Clean and transform raw input data
5. **Validate** - Check against validation rules with custom messages
6. **Postprocess** - Apply final value transformations and formatting

### Advanced Example

```php
Field::for('categories')
    ->mapFrom('meta.category_list')
    ->preprocess(fn(mixed $value): array => 
        is_string($value) ? explode(',', $value) : (array)$value
    )
    ->validate('array|min:1|max:10')
    ->postprocess(fn(array $value): array => 
        array_map('intval', array_unique($value))
    )
```

---

## 🏗️ Architecture Overview

The system employs a modular architecture with clear separation of concerns:

**RequestProcessor** - Central coordinator managing the complete processing pipeline with dependency injection for all components.

**DataExtractor** - Handles data extraction from requests, field mapping via dot notation, and preprocessing/postprocessing transformations.

**Authorizer** - Manages authorization checks through simple interface integration with existing access control systems.

**DataValidator** - Provides validation services with Solo Validator integration and comprehensive error message support.

---

## 🔄 Request Data Handling

- **Nested Structures**: Use dot notation (`mapFrom('user.profile.contact.email')`)
- **GET**: Query parameters only
- **POST/PUT/PATCH**: Merged body and query parameters (body takes priority)
- **Files**: Access via `$request->getUploadedFiles()` with PSR-7 compatibility

---

## ⚡ Error Handling

### ValidationException (HTTP 422)
```php
catch (ValidationException $e) {
    return ['errors' => $e->getErrors()]; // Format: ['field' => ['Error message']]
}
```

### AuthorizationException (HTTP 403)
```php
catch (AuthorizationException $e) {
    return ['message' => $e->getMessage()]; // "Unauthorized request"
}
```

---

## 🚦 Custom Messages

```php
protected function messages(): array 
{
    return [
        'author_email.required' => 'Author email is required for article creation',
        'author_email.email' => 'Please provide a valid email address',
        'status.in' => 'Status must be either draft or published',
        'title.max' => 'Article title must not exceed :max characters'
    ];
}
```

---

## 🗂️ Repository Integration Helpers

The `ParameterParser` helper class provides static methods for parsing request parameters into repository-compatible formats, designed to work seamlessly with repository patterns.

### Index/List Request Pattern

```php
<?php declare(strict_types=1);

namespace App\Requests;

use Solo\RequestHandler\AbstractRequestHandler;
use Solo\RequestHandler\Field;
use Solo\RequestHandler\Helpers\ParameterParser;

final readonly class UserIndexRequest extends AbstractRequestHandler
{
    protected function fields(): array
    {
        return [
            Field::for('page')
                ->default(1)
                ->validate('integer|min:1')
                ->postprocess(fn($v) => (int)$v),

            Field::for('per_page')
                ->default(15)
                ->validate('integer|min:1|max:100')
                ->postprocess(fn($v) => (int)$v),

            Field::for('sort')
                ->postprocess(fn($v) => ParameterParser::sort($v)),

            Field::for('filter')
                ->postprocess(fn($v) => ParameterParser::filter($v)),
        ];
    }
}
```

### ParameterParser Helper Methods

The `ParameterParser` helper class provides static methods for parsing request parameters into repository-compatible formats:

#### `ParameterParser::sort(?string $sort): ?array`

Converts sort parameter from URL format to repository format:
- `?sort=name` → `['name' => 'ASC']`
- `?sort=-created_at` → `['created_at' => 'DESC']`

#### `ParameterParser::filter($filter): array`

Parses filter parameter for repository filtering:
- `?filter[status]=active&filter[role]=admin` → `['filter' => ['status' => 'active', 'role' => 'admin']]`

#### `ParameterParser::boolean(mixed $value): int`

Converts boolean values to MySQL-compatible integers (0 or 1):
- `true`, `"true"`, `"1"`, `"yes"`, `"on"` → `1`
- `false`, `"false"`, `"0"`, `"no"`, `"off"` → `0`

#### `ParameterParser::search(mixed $search): array`

Parses search parameter for repository filtering:
- Returns array of search terms or empty array

#### `ParameterParser::uniqueId(int $length = 8): int`

Generates a unique integer ID with specified length:
- `ParameterParser::uniqueId()` → generates 8-digit unique ID (default)
- `ParameterParser::uniqueId(10)` → generates 10-digit unique ID
- Uses timestamp and random components for uniqueness

```php
<?php declare(strict_types=1);

use Solo\RequestHandler\Helpers\ParameterParser;

// Parse sorting parameters
$sortData = ParameterParser::sort('-created_at'); // ['created_at' => 'DESC']

// Parse filter parameters
$filters = ParameterParser::filter(['status' => 'active']); // ['filter' => ['status' => 'active']]

// Parse boolean values
$isActive = ParameterParser::boolean('yes'); // 1
$isDeleted = ParameterParser::boolean('false'); // 0

// Parse search parameters
$searchTerms = ParameterParser::search('john doe'); // ['john doe']

// Generate unique IDs
$id = ParameterParser::uniqueId(); // 12345678 (8-digit)
$longId = ParameterParser::uniqueId(12); // 123456789012 (12-digit)
```

### Usage in Controllers

```php
<?php declare(strict_types=1);

namespace App\Controllers;

use App\Requests\UserIndexRequest;
use App\Repositories\UserRepository;

final class UserController
{
    public function index(ServerRequestInterface $request, UserIndexRequest $indexRequest): array
    {
        $data = $indexRequest->handle($request);
        
        // Clean, validated data ready for repository
        $users = $this->userRepository->getBy(
            criteria: $data['filter'],    // ['filter' => [...]] or []
            orderBy: $data['sort'],       // ['field' => 'ASC/DESC'] or null
            perPage: $data['per_page'],   // int
            page: $data['page']           // int
        );
        
        $total = $this->userRepository->countBy($data['filter']);
        
        return $this->paginate($users, $data['page'], $data['per_page'], $total);
    }
}
```

### URL Examples

```bash
# Basic pagination
GET /users?page=2&per_page=25

# Sorting (ascending)
GET /users?sort=created_at

# Sorting (descending)
GET /users?sort=-name

# Filtering
GET /users?filter[status]=active&filter[role]=admin

# Combined
GET /users?sort=-created_at&filter[status]=active&page=2&per_page=10
```

---

## 📚 Public API

| Method                                           | Description                                                              |
|--------------------------------------------------|--------------------------------------------------------------------------|
| `handle(ServerRequestInterface $request): array` | Main entry point: processes complete request pipeline                  |
| `getFields(): array`                            | Returns field definitions for the handler                               |
| `getMessages(): array`                          | Returns custom validation error messages                                |
| `isAuthorized(): bool`                          | Checks authorization status for the request                             |

---

## 🔧 Advanced Usage

### Component Customization with Factory Methods

For advanced use cases, you can customize individual components by overriding factory methods:

```php
final class ApiArticleRequest extends AbstractRequestHandler
{
    // Custom authorization logic
    protected function createAuthorizer(): AuthorizerInterface
    {
        return new ApiTokenAuthorizer();
    }
    
    // Custom data extraction for JSON API
    protected function createDataExtractor(): DataExtractorInterface
    {
        return new JsonApiDataExtractor();
    }

    protected function fields(): array
    {
        return [
            Field::for('data')->mapFrom('json.data')->validate('required|array')
        ];
    }
}
```

## ⚙️ Requirements

- **PHP 8.2+** with strict typing support
- **PSR-7 HTTP Message Interface** for request/response handling
- **Validator** implementing `Solo\Contracts\Validator\ValidatorInterface`

---

## 🎯 Performance Features

- **Readonly Properties** - Optimal opcache performance with immutable objects
- **Minimal Memory Footprint** - Efficient dependency injection patterns
- **Interface-based Design** - Clean architecture with separated concerns
- **Component Reuse** - Efficient object creation with factory method pattern

---

## 📄 License

MIT License - See [LICENSE](LICENSE) for complete terms and conditions.