<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ReturnStmt implements Stmt
{
    public function __construct(public ?Expr $value) {}
}
