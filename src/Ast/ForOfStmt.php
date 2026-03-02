<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ForOfStmt implements Stmt
{
    public function __construct(
        public VarKind $kind,
        public string  $name,
        public Expr    $iterable,
        public Stmt    $body,
    ) {}
}
