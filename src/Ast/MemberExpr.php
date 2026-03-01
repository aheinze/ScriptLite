<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Property access: obj.prop (computed=false) or obj[expr] (computed=true).
 */
final readonly class MemberExpr implements Expr
{
    public function __construct(
        public Expr $object,
        public Expr $property,
        public bool $computed,
        public bool $optional = false,
    ) {}
}
