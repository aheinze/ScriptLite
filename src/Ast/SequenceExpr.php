<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Comma-separated expression sequence (comma operator).
 * Evaluates all expressions left-to-right, returns the last value.
 * e.g. i++, j-- in for-loop updates
 */
final readonly class SequenceExpr implements Expr
{
    /** @param Expr[] $expressions */
    public function __construct(
        public array $expressions,
    ) {}
}
