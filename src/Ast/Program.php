<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class Program implements Node
{
    /** @param Stmt[] $body */
    public function __construct(public array $body) {}
}
