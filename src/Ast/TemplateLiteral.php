<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

/**
 * Template literal: `text ${expr} text`
 *
 * @property string[] $quasis      String parts (always count(expressions) + 1)
 * @property Expr[]   $expressions Interpolated expressions
 */
final readonly class TemplateLiteral implements Expr
{
    /** @param string[] $quasis @param Expr[] $expressions */
    public function __construct(
        public array $quasis,
        public array $expressions,
    ) {}
}
