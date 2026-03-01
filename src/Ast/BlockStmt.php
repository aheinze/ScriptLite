<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class BlockStmt implements Stmt
{
    /** @param Stmt[] $statements */
    public function __construct(public array $statements) {}
}
