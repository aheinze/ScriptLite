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
     */
    public function __construct(
        public ?string $name,
        public array   $params,
        public array   $body,
        public bool    $isArrow = false,
        public ?string $restParam = null,
    ) {}
}
