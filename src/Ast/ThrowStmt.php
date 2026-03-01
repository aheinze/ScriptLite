<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ThrowStmt implements Stmt
{
    public function __construct(
        public Expr $argument,
    ) {}
}
