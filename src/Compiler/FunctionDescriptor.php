<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

/**
 * Describes a compiled function. The VM uses this to set up a new call frame.
 *
 * Bytecode is stored as three parallel flat arrays (ops/opA/opB) instead of
 * Instruction objects. This cuts per-instruction memory from ~136 bytes to ~24
 * bytes and eliminates object property access in the hot dispatch loop.
 */
final readonly class FunctionDescriptor
{
    /**
     * @param string[]      $params      Parameter names
     * @param OpCode[]      $ops         Opcode per instruction
     * @param int[]         $opA         OperandA per instruction
     * @param int[]         $opB         OperandB per instruction
     * @param array         $constants   Constant pool for this function
     * @param string[]      $names       Name pool for variable references
     * @param int           $regCount    Number of register slots needed
     * @param int[]         $paramSlots  Register slot per param (-1 = environment-allocated)
     */
    public function __construct(
        public ?string $name,
        public array   $params,
        public array   $ops,
        public array   $opA,
        public array   $opB,
        public array   $constants,
        public array   $names,
        public int     $regCount = 0,
        public array   $paramSlots = [],
        public ?string $restParam = null,
        public int     $restParamSlot = -1,
    ) {}
}
