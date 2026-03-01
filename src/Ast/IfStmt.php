<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class IfStmt implements Stmt
{
    public function __construct(
        public Expr  $condition,
        public Stmt  $consequent,
        public ?Stmt $alternate,
    ) {}
}
