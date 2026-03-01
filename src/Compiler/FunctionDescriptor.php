<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

/**
 * Describes a compiled function. The VM uses this to set up a new call frame.
 */
final readonly class FunctionDescriptor
{
    /**
     * @param string[]      $params      Parameter names
     * @param Instruction[] $code        Compiled bytecode for the function body
     * @param array         $constants   Constant pool for this function
     * @param string[]      $names       Name pool for variable references
     * @param int           $regCount    Number of register slots needed
     * @param int[]         $paramSlots  Register slot per param (-1 = environment-allocated)
     */
    public function __construct(
        public ?string $name,
        public array   $params,
        public array   $code,
        public array   $constants,
        public array   $names,
        public int     $regCount = 0,
        public array   $paramSlots = [],
        public ?string $restParam = null,
        public int     $restParamSlot = -1,
    ) {}
}
