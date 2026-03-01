<?php

declare(strict_types=1);

namespace ScriptLite\Runtime;

use ScriptLite\Compiler\FunctionDescriptor;

/**
 * A runtime closure: captures the environment at the point of creation.
 * This is how we implement JS closures — each function expression "closes over"
 * its lexical environment.
 */
final readonly class JsClosure
{
    public function __construct(
        public FunctionDescriptor $descriptor,
        public Environment        $capturedEnv,
    ) {}
}
