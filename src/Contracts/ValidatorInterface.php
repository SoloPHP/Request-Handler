<?php declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

interface ValidatorInterface
{
    /**
     * Validate data against rules with optional custom messages
     *
     * @param array<string, mixed> $data Data to validate
     * @param array<string, string> $rules Validation rules ['field' => 'required|email']
     * @param array<string, string> $messages Custom error messages ['field.required' => 'Field is required']
     * @return array<string, array<string>> Validation errors ['field' => ['Error message']]
     */
    public function validate(array $data, array $rules, array $messages = []): array;
}