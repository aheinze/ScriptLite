<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ObjectProperty
{
    public function __construct(
        public string $key,
        public Expr $value,
    ) {}
}
