<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

/**
 * Sentinel value representing JavaScript's `undefined`.
 * PHP's null maps to JS null; we need a distinct type for undefined.
 */
enum JsUndefined
{
    case Value;
}
