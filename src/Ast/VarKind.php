<?php

declare(strict_types=1);

namespace ScriptLite\Ast;

enum VarKind: string
{
    case Var = 'var';
    case Let = 'let';
    case Const = 'const';
}
