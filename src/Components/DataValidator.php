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
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data, RequestHandlerInterface $handler): void
    {
        $rules = $this->buildValidationRules($handler->getFields(), $data);

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
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function buildValidationRules(array $fields, array $data): array
    {
        $rules = [];
        foreach ($fields as $field) {
            if (!empty($field->rules)) {
                $isRequired = str_contains($field->rules, 'required');
                if (array_key_exists($field->name, $data) || $isRequired) {
                    $rules[$field->name] = $field->rules;
                }
            }
        }
        return $rules;
    }
}
