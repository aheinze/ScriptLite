<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ExpressionStmt implements Stmt
{
    public function __construct(public Expr $expression) {}
}
