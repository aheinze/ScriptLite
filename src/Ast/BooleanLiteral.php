<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class BooleanLiteral implements Expr
{
    public function __construct(public bool $value) {}
}
