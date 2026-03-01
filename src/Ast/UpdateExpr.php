<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class UpdateExpr implements Expr
{
    public function __construct(
        public string $operator,  // '++' or '--'
        public Expr   $argument,  // Identifier or MemberExpr
        public bool   $prefix,    // true = ++x, false = x++
    ) {}
}
