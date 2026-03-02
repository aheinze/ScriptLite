<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Destructuring variable declaration.
 *
 * Handles both array patterns: let [a, b] = expr
 * and object patterns: let {x, y} = expr
 */
final readonly class DestructuringDeclaration implements Stmt
{
    /**
     * @param VarKind $kind var/let/const
     * @param array<array{name: string, source: string|int, default: ?Expr}> $bindings
     * @param ?string $restName rest element name (e.g. ...rest)
     * @param Expr $initializer The right-hand side expression
     * @param bool $isArray True for array destructuring, false for object
     */
    public function __construct(
        public VarKind $kind,
        public array   $bindings,
        public ?string $restName,
        public Expr    $initializer,
        public bool    $isArray,
    ) {}
}
