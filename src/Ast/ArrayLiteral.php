<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ArrayLiteral implements Expr
{
    /** @param Expr[] $elements */
    public function __construct(
        public array $elements,
    ) {}
}
