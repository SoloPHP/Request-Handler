# Installation

## Requirements

- PHP 8.3 or higher
- PSR-7 HTTP Message implementation
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`
- Optional: a PSR-11 container for normalizers, casters, post-processors and generators with constructor dependencies

## Composer

Install the package via Composer:

```bash
composer require solophp/request-handler
```

::: tip Upgrading from 3.x
4.0 replaces `#[PreProcess]` with `#[Normalize]`, `#[Caster]` and `#[PostProcess]`, makes casts lossless, keys errors and `schema()` by input key, and replaces `#[Cast('datetime…')]` with `#[DateFormat]`. See [UPGRADE.md](https://github.com/solophp/request-handler/blob/main/UPGRADE.md) for the checklist.
:::

## Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "psr/container": "^2.0",
        "psr/http-message": "^2.0",
        "solophp/contracts": "^1.3"
    }
}
```

## Validator

The package requires a validator implementing `Solo\Contracts\Validator\ValidatorInterface`. `validate()` gets the rules of every field, also of one the client did not send — its key is then missing from the data, and the validator decides what that means. We recommend using [solophp/validator](https://github.com/SoloPHP/Validator), which checks an absent field by its presence rules only:

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
