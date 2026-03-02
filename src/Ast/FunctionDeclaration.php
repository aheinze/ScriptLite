<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class FunctionDeclaration implements Stmt
{
    /**
     * @param string   $name
     * @param string[] $params
     * @param Stmt[]   $body
     * @param array<int, ?Expr> $defaults  Default value expressions, indexed same as $params
     */
    public function __construct(
        public string  $name,
        public array   $params,
        public array   $body,
        public ?string $restParam = null,
        public array   $defaults = [],
    ) {}
}
