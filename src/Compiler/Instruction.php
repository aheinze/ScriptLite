<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

/**
 * A single VM instruction. Kept as a simple final class (not readonly, so we can patch jump targets).
 *
 * Memory layout: 3 machine words (enum + int + int). Compact and cache-friendly.
 */
final class Instruction
{
    public function __construct(
        public readonly OpCode $op,
        public int             $operandA = 0,
        public int             $operandB = 0,
    ) {}
}
