<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

final readonly class CatchClause implements Node
{
    public function __construct(
        public string $param,
        public BlockStmt $body,
    ) {}
}
