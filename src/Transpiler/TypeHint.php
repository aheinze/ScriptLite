<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler;

/**
 * Lightweight type hints for transpiler optimization.
 *
 * Not a full type system — just enough to eliminate is_string() guards
 * on the + operator and is_string()/count() guards on .length.
 */
enum TypeHint
{
    case Numeric;   // int or float — + means arithmetic
    case String;    // string — + means concat
    case Bool;      // boolean
    case Array_;    // array (known)
    case Object_;   // plain JS object literal (boxed JSObject, no prototype walk needed)
    case Function;  // ScriptLite-created closure/function object
    case Unknown;   // need the runtime guard
}
