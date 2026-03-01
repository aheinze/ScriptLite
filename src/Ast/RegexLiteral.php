<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class RegexLiteral implements Expr
{
    public function __construct(
        public string $pattern,
        public string $flags,
    ) {}
}
