<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Assignment to a member expression: obj[key] = value or obj.prop = value.
 */
final readonly class MemberAssignExpr implements Expr
{
    public function __construct(
        public Expr $object,
        public Expr $property,
        public bool $computed,
        public string $operator,
        public Expr $value,
    ) {}
}
