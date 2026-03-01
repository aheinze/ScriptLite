<?php

declare(strict_types=1);

namespace ScriptLite\Lexer;

use RuntimeException;

final class LexerException extends RuntimeException
{
    public function __construct(string $message, public readonly int $sourceLine, public readonly int $sourceCol)
    {
        parent::__construct("{$message} at line {$sourceLine}, col {$sourceCol}");
    }
}
