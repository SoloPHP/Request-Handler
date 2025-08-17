<?php

namespace Solo\RequestHandler\Components;

use Solo\RequestHandler\Contracts\RequestHandlerInterface;
use Solo\RequestHandler\Contracts\DataValidatorInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\RequestHandler\Exceptions\ValidationException;
use Solo\RequestHandler\Field;

final readonly class DataValidator implements DataValidatorInterface
{
    public function __construct(
        private ValidatorInterface $validator
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data, RequestHandlerInterface $handler): void
    {
        $rules = $this->buildValidationRules($handler->getFields());

        if (empty($rules)) {
            return;
        }

        $errors = $this->validator->validate($data, $rules, $handler->getMessages());

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Build validation rules array from field definitions
     *
     * @param array<Field> $fields
     * @return array<string, string>
     */
    private function buildValidationRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $field) {
            if (!empty($field->rules)) {
                $rules[$field->name] = $field->rules;
            }
        }
        return $rules;
    }
}