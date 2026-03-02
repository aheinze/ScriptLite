<?php

declare(strict_types=1);

namespace ScriptLite\Vm;

use ScriptLite\Compiler\OpCode;
use ScriptLite\Runtime\Environment;
use ScriptLite\Runtime\JsObject;

/**
 * Represents a single activation record on the call stack.
 * Each function invocation creates a new frame.
 *
 * Properties are non-readonly to allow frame reuse (pooling).
 */
final class CallFrame
{
    public int $ip = 0;

    /** @var mixed[] Register file for non-captured local variables */
    public array $registers = [];

    /**
     * @param OpCode[]    $ops             Opcodes (flat array)
     * @param int[]       $opA             OperandA per instruction
     * @param int[]       $opB             OperandB per instruction
     * @param array       $constants       Constant pool
     * @param string[]    $names           Name pool
     * @param Environment $env             Lexical environment for this frame
     * @param int         $stackBase       Base offset into the VM's value stack
     * @param JsObject|null $constructTarget If non-null, this frame is a constructor call
     */
    public function __construct(
        public array       $ops,
        public array       $opA,
        public array       $opB,
        public array       $constants,
        public array       $names,
        public Environment $env,
        public int         $stackBase,
        public ?JsObject   $constructTarget = null,
    ) {}

    /**
     * Reset frame for reuse (avoids allocation).
     */
    public function reset(array $ops, array $opA, array $opB, array $constants, array $names, Environment $env, int $stackBase, ?JsObject $constructTarget = null): void
    {
        $this->ops = $ops;
        $this->opA = $opA;
        $this->opB = $opB;
        $this->constants = $constants;
        $this->names = $names;
        $this->env = $env;
        $this->stackBase = $stackBase;
        $this->constructTarget = $constructTarget;
        $this->ip = 0;
        $this->registers = [];
    }
}
