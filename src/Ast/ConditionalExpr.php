<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ConditionalExpr implements Expr
{
    public function __construct(
        public Expr $condition,
        public Expr $consequent,
        public Expr $alternate,
    ) {}
}
