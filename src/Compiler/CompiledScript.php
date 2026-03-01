<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

/**
 * The output of compilation: a top-level function descriptor (the "main" script).
 */
final readonly class CompiledScript
{
    public function __construct(
        public FunctionDescriptor $main,
    ) {}
}
