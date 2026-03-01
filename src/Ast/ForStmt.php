<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ForStmt implements Stmt
{
    public function __construct(
        public ?Node $init,       // VarDeclaration | ExpressionStmt | null
        public ?Expr $condition,
        public ?Expr $update,
        public Stmt  $body,
    ) {}
}
