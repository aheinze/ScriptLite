<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class NumberLiteral implements Expr
{
    public function __construct(public float $value) {}
}
