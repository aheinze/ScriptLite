<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class TypeofExpr implements Expr
{
    public function __construct(public Expr $operand) {}
}
