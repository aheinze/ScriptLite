<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class SwitchStmt implements Stmt
{
    /** @param SwitchCase[] $cases */
    public function __construct(
        public Expr $discriminant,
        public array $cases,
    ) {}
}
