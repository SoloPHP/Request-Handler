<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Contracts;

use Solo\RequestHandler\ProcessContext;

/**
 * Pure conversion of normalized input into the property's declared type.
 * Reject input with FieldValidationException. A result of the wrong type is
 * a programming error. Null/blank policy is applied before invoking the caster.
 *
 * @template-covariant T
 */
interface CasterInterface
{
    /** @return T */
    public function cast(mixed $value, ProcessContext $context): mixed;
}
