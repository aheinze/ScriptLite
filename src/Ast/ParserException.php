<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

use ScriptLite\Lexer\Token;
use RuntimeException;

final class ParserException extends RuntimeException
{
    public function __construct(string $message, ?Token $token = null)
    {
        $loc = $token ? " at line {$token->line}, col {$token->col}" : '';
        parent::__construct($message . $loc);
    }
}
