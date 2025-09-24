<?php

declare(strict_types=1);

namespace Solo\RequestHandler;

/**
 * Immutable value object representing a request field definition.
 *
 * This class provides a fluent interface for configuring field behavior:
 * - Input mapping from nested structures using dot notation
 * - Default values for missing fields
 * - Validation rules
 * - Preprocessing and postprocessing callbacks
 *
 * Example usage:
 * ```php
 * Field::for('email')
 *     ->mapFrom('user.profile.email')
 *     ->validate('required|email')
 *     ->preprocess(fn($v) => trim($v))
 *     ->postprocess(fn($v) => strtolower($v))
 * ```
 */
final readonly class Field
{
    private const NO_DEFAULT = '__NO_DEFAULT__';
    public string $inputName;

    public function __construct(
        public string $name,
        public mixed $default = self::NO_DEFAULT,
        public ?string $rules = null,
        public mixed $preprocessor = null,
        public mixed $postprocessor = null,
        ?string $inputName = null
    ) {
        $this->inputName = $inputName ?? $name;
    }

    public static function for(string $name): self
    {
        return new self($name);
    }

    public function mapFrom(string $inputName): self
    {
        return new self(
            name: $this->name,
            default: $this->default,
            rules: $this->rules,
            preprocessor: $this->preprocessor,
            postprocessor: $this->postprocessor,
            inputName: $inputName
        );
    }

    public function default(mixed $value): self
    {
        return new self(
            name: $this->name,
            default: $value,
            rules: $this->rules,
            preprocessor: $this->preprocessor,
            postprocessor: $this->postprocessor,
            inputName: $this->inputName
        );
    }

    public function validate(string $rules): self
    {
        return new self(
            name: $this->name,
            default: $this->default,
            rules: $rules,
            preprocessor: $this->preprocessor,
            postprocessor: $this->postprocessor,
            inputName: $this->inputName
        );
    }

    public function preprocess(callable $handler): self
    {
        return new self(
            name: $this->name,
            default: $this->default,
            rules: $this->rules,
            preprocessor: $handler,
            postprocessor: $this->postprocessor,
            inputName: $this->inputName
        );
    }

    public function postprocess(callable $handler): self
    {
        return new self(
            name: $this->name,
            default: $this->default,
            rules: $this->rules,
            preprocessor: $this->preprocessor,
            postprocessor: $handler,
            inputName: $this->inputName
        );
    }

    public function processPre(mixed $value): mixed
    {
        return $this->preprocessor && is_callable($this->preprocessor)
            ? ($this->preprocessor)($value)
            : $value;
    }

    public function processPost(mixed $value): mixed
    {
        return $this->postprocessor && is_callable($this->postprocessor)
            ? ($this->postprocessor)($value)
            : $value;
    }

    public function hasDefault(): bool
    {
        return $this->default !== self::NO_DEFAULT;
    }
}
