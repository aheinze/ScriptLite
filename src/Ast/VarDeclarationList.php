<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Multiple variable declarations sharing the same kind.
 * e.g. let a = 1, b = 2;
 */
final readonly class VarDeclarationList implements Stmt
{
    /** @param VarDeclaration[] $declarations */
    public function __construct(
        public array $declarations,
    ) {}
}
