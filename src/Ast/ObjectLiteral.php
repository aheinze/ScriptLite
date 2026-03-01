<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class ObjectLiteral implements Expr
{
    /** @param ObjectProperty[] $properties */
    public function __construct(
        public array $properties,
    ) {}
}
