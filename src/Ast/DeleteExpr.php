<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class DeleteExpr implements Expr
{
    public function __construct(
        public Expr $operand,
    ) {}
}
