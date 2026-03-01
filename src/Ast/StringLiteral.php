<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class StringLiteral implements Expr
{
    public function __construct(public string $value) {}
}
