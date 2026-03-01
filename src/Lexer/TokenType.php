<?php

declare(strict_types=1);

namespace ScriptLite\Lexer;

/**
 * Backed enum for token types. Integer-backed for fast switch/match in the VM hot loop.
 * Grouped by category for cache-line locality when the Zend Engine handles match arms.
 */
enum TokenType: int
{
    // Literals (0-9)
    case Number = 0;
    case String = 1;
    case True = 2;
    case False = 3;
    case Null = 4;
    case Undefined = 5;
    case Regex = 6;
    case TemplateHead = 7;     // `text${
    case TemplateMiddle = 8;   // }text${
    case TemplateTail = 9;     // }text`

    // Identifiers & Keywords (10-29)
    case Identifier = 10;
    case Var = 11;
    case Let = 12;
    case Const = 13;
    case Function = 14;
    case Return = 15;
    case If = 16;
    case Else = 17;
    case While = 18;
    case For = 19;
    case Break = 20;
    case Continue = 21;
    case Typeof = 22;
    case This = 23;
    case New = 24;
    case Switch = 25;
    case Case = 26;
    case Default = 27;
    case Do = 28;
    case Try = 29;
    case Catch = 84;    // keyword but placed in delimiter range (keyword range full)
    case Throw = 85;
    case Spread = 86;   // ...

    // Operators — Arithmetic (30-39)
    case Plus = 30;
    case Minus = 31;
    case Star = 32;
    case Slash = 33;
    case Percent = 34;
    case PlusPlus = 35;          // ++
    case MinusMinus = 36;        // --
    case StarStar = 37;          // **

    // Operators — Comparison (40-49)
    case EqualEqual = 40;
    case NotEqual = 41;
    case StrictEqual = 42;
    case StrictNotEqual = 43;
    case Less = 44;
    case LessEqual = 45;
    case Greater = 46;
    case GreaterEqual = 47;

    // Operators — Logical & Bitwise (50-59)
    case And = 50;
    case Or = 51;
    case Not = 52;
    case Ampersand = 53;         // &
    case Pipe = 54;              // |
    case Caret = 55;             // ^
    case Tilde = 56;             // ~
    case LeftShift = 57;         // <<
    case RightShift = 58;        // >>
    case UnsignedRightShift = 59; // >>>

    // Operators — Assignment (60-69, 87-92)
    case Equal = 60;
    case PlusEqual = 61;
    case MinusEqual = 62;
    case StarEqual = 63;
    case SlashEqual = 64;
    case StarStarEqual = 65;     // **=
    case PercentEqual = 66;      // %=
    case NullishCoalesceEqual = 67; // ??=
    case AmpersandEqual = 68;    // &=
    case PipeEqual = 87;         // |=
    case CaretEqual = 88;        // ^=
    case LeftShiftEqual = 89;    // <<=
    case RightShiftEqual = 91;   // >>=
    case UnsignedRightShiftEqual = 92; // >>>=

    // Delimiters (70-89)
    case LeftParen = 70;
    case RightParen = 71;
    case LeftBrace = 72;
    case RightBrace = 73;
    case LeftBracket = 74;
    case RightBracket = 75;
    case Semicolon = 76;
    case Comma = 77;
    case Dot = 78;
    case Arrow = 79;   // =>
    case Colon = 80;
    case Question = 81;  // ?
    case OptionalChain = 82;  // ?.
    case NullishCoalesce = 83;  // ??

    // Keywords — operators (93-96)
    case Void = 93;
    case Delete = 94;
    case In = 95;
    case Instanceof = 96;

    // Special
    case Eof = 90;
}
