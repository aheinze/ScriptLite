<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class TryCatchStmt implements Stmt
{
    public function __construct(
        public BlockStmt $block,
        public ?CatchClause $handler,
    ) {}
}
