<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class UnaryExpr implements Expr
{
    public function __construct(
        public string $operator,
        public Expr   $operand,
    ) {}
}
