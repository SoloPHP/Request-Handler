# Request Handler 🛡️

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/request-handler.svg)](https://packagist.org/packages/solophp/request-handler)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)](https://php.net/)

**Robust request validation & authorization layer for HTTP inputs with type-safe handlers and modern PHP 8+ architecture**

---

## ✨ Features

- **Type-safe request processing** with `declare(strict_types=1)` and readonly properties
- **Modular component architecture** with separated concerns and dependency injection
- **DI-container friendly** - clean classes without complex constructors
- **Vendor-independent validation** - use any validator implementing our interface
- **Field mapping** from custom input names and nested structures via dot notation
- **Multi-stage processing pipeline** (`extract` → `authorize` → `preprocess` → `validate` → `postprocess`)
- **Built-in authorization framework** with simple override mechanisms
- **GET query parameter optimization** with automatic cleanup and redirect generation
- **Custom error messages** with detailed validation feedback
- **PSR-7 compatible** with full HTTP message interface support
- **Immutable field definitions** using readonly value objects
- **Factory methods** for component customization without breaking simplicity

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
use Solo\RequestHandler\Exceptions\{ValidationException, AuthorizationException, UncleanQueryException};

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
        } catch (UncleanQueryException $e) {
            header('Location: ' . $e->redirectUri, true, 302);
            exit;
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

### Processing Pipeline

1. **Extract Data** - Merge POST body and GET parameters (body priority)
2. **Clean Query** - Remove default values from GET requests and redirect if needed
3. **Authorize** - Check user permissions via `authorize()` method
4. **Map Input** - Resolve values using `mapFrom` paths with dot notation
5. **Preprocess** - Clean and transform raw input data
6. **Validate** - Check against validation rules with custom messages
7. **Postprocess** - Apply final value transformations and formatting

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

**QueryCleaner** - Maintains clean URLs for GET requests by removing default parameters and generating redirect responses.

---

## 🔄 Request Data Handling

- **Nested Structures**: Use dot notation (`mapFrom('user.profile.contact.email')`)
- **GET**: Query parameters only with automatic cleanup
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

### UncleanQueryException (HTTP 302)
```php
catch (UncleanQueryException $e) {
    return redirect($e->redirectUri); // Redirect to clean URL
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

## 📚 Public API

| Method                                           | Description                                                              |
|--------------------------------------------------|--------------------------------------------------------------------------|
| `handle(ServerRequestInterface $request): array` | Main entry point: processes complete request pipeline                  |
| `getFields(): array`                            | Returns field definitions for the handler                               |
| `getDefaults(): array`                          | Returns all non-null default field values                              |
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
    
    // Custom query parameter handling
    protected function createQueryCleaner(): QueryCleanerInterface
    {
        return new StrictQueryCleaner();
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