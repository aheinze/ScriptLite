<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class FunctionDeclaration implements Stmt
{
    /**
     * @param string   $name
     * @param string[] $params
     * @param Stmt[]   $body
     */
    public function __construct(
        public string  $name,
        public array   $params,
        public array   $body,
        public ?string $restParam = null,
    ) {}
}
