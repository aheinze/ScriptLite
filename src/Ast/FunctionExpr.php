<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Covers both function expressions and arrow functions.
 */
final readonly class FunctionExpr implements Expr
{
    /**
     * @param string[]  $params
     * @param Stmt[]    $body
     * @param array<int, ?Expr> $defaults  Default value expressions, indexed same as $params
     */
    public function __construct(
        public ?string $name,
        public array   $params,
        public array   $body,
        public bool    $isArrow = false,
        public ?string $restParam = null,
        public array   $defaults = [],
    ) {}
}
