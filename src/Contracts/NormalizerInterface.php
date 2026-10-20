<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Solo\RequestHandler\ProcessContext;

/**
 * Pure normalization of present raw input before validation.
 * Accept every input type, including null and "". Leave an unsupported value
 * for validation, or reject the field with FieldValidationException.
 */
interface NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed;
}
