<?php

declare(strict_types=1);

namespace ScriptLite\Vm;

/**
 * Wraps a JS-thrown value as a PHP exception so it can propagate
 * through the PHP call stack and be caught by the VM's handler mechanism.
 */
final class JsThrowable extends \RuntimeException
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct(is_string($value) ? $value : 'JS Exception');
    }
}
