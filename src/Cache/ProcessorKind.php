<?php

declare(strict_types=1);

namespace Solo\RequestHandler\Cache;

/**
 * Pre-classified dispatch kind for a pre/postProcess handler.
 *
 * Resolved once at metadata build time so runtime dispatch is a single match
 * rather than a chain of function_exists/class_exists/method_exists checks.
 */
enum ProcessorKind
{
    case Func;
    case ProcessorInterface;
    case CasterInterface;
    case StaticMethod;
}
