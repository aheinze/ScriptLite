<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class AssignExpr implements Expr
{
    public function __construct(
        public string $name,
        public string $operator, // '=', '+=', '-=', '*=', '/='
        public Expr   $value,
    ) {}
}
