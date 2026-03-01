<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Represents a spread element: `...expr`
 * Used in array literals, call arguments, and new arguments.
 */
final readonly class SpreadElement implements Expr
{
    public function __construct(
        public Expr $argument,
    ) {}
}
