<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class BinaryExpr implements Expr
{
    public function __construct(
        public Expr   $left,
        public string $operator,
        public Expr   $right,
    ) {}
}
