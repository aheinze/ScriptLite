<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class NewExpr implements Expr
{
    /** @param Expr[] $arguments */
    public function __construct(
        public Expr $callee,
        public array $arguments,
    ) {}
}
