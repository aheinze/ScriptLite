<?php

declare(strict_types=1);

namespace ScriptLite\Lexer;

/**
 * Immutable token. Uses readonly to avoid mutation bugs and lets PHP intern the class layout.
 * The $value is a string slice reference — we never copy substrings during lexing if the source
 * string stays alive (PHP's copy-on-write handles this at the zval level).
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string    $value,
        public int       $line,
        public int       $col,
    ) {}
}
