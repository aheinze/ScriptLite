<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class LogicalExpr implements Expr
{
    public function __construct(
        public Expr   $left,
        public string $operator, // '&&' or '||'
        public Expr   $right,
    ) {}
}
