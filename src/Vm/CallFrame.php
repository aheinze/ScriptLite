<?php

declare(strict_types=1);

namespace ScriptLite\Vm;

use ScriptLite\Compiler\Instruction;
use ScriptLite\Runtime\Environment;
use ScriptLite\Runtime\JsObject;

/**
 * Represents a single activation record on the call stack.
 * Each function invocation creates a new frame.
 */
final class CallFrame
{
    public int $ip = 0;

    /** @var mixed[] Register file for non-captured local variables */
    public array $registers = [];

    /**
     * @param Instruction[] $code           Bytecode for this frame
     * @param array         $constants      Constant pool
     * @param string[]      $names          Name pool
     * @param Environment   $env            Lexical environment for this frame
     * @param int           $stackBase      Base offset into the VM's value stack
     * @param JsObject|null $constructTarget If non-null, this frame is a constructor call
     */
    public function __construct(
        public readonly array       $code,
        public readonly array       $constants,
        public readonly array       $names,
        public Environment $env,
        public readonly int         $stackBase,
        public readonly ?JsObject   $constructTarget = null,
    ) {}
}
