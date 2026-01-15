# Installation

## Requirements

- PHP 8.2 or higher
- PSR-7 HTTP Message implementation
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`

## Composer

Install the package via Composer:

```bash
composer require solophp/request-handler
```

## Dependencies

```json
{
    "require": {
        "php": ">=8.2",
        "psr/http-message": "^1.0 || ^2.0"
    }
}
```

## Validator

The package requires a validator implementing `Solo\Contracts\Validator\ValidatorInterface`. We recommend using [solophp/validator](https://github.com/SoloPHP/Validator):

```bash
composer require solophp/validator
```

## Basic Setup

```php
use Solo\RequestHandler\RequestHandler;
use Solo\Validator\Validator;

// Create validator
$validator = new Validator();

// Create request handler
$requestHandler = new RequestHandler($validator);
```

## Next Steps

- [Quick Start](/guide/quick-start) — Create your first Request DTO
- [Configuration](/guide/configuration) — Learn about configuration options
