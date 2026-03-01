<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class Identifier implements Expr
{
    public function __construct(public string $name) {}
}
