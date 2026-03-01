<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class SwitchCase implements Node
{
    /** @param Stmt[] $consequent */
    public function __construct(
        public ?Expr $test,
        public array $consequent,
    ) {}
}
