<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class CallExpr implements Expr
{
    /**
     * @param Expr   $callee
     * @param Expr[] $arguments
     */
    public function __construct(
        public Expr  $callee,
        public array $arguments,
        public bool  $optional = false,
        public bool  $optionalChain = false,
    ) {}
}
