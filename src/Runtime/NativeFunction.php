<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

use Closure;

/**
 * Wraps a PHP callable as a JS-invocable function.
 * Used for built-in functions like console.log, Math.*, etc.
 *
 * The optional $properties array enables "function as object" semantics —
 * e.g., Date is both a constructor and has static methods (Date.now).
 */
final readonly class NativeFunction
{
    /** @var array<string, mixed> */
    public array $properties;

    /**
     * @param array<string, mixed> $properties Static properties on this function
     */
    public function __construct(
        public string  $name,
        public Closure $callable,
        array $properties = [],
    ) {
        $this->properties = $properties;
    }
}
