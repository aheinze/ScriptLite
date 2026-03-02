<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

/**
 * Integer-backed enum for VM opcodes.
 *
 * Why integer-backed? PHP 8's match() on int-backed enums compiles to a jump table
 * in OPcache/JIT, giving O(1) dispatch — critical for the VM hot loop.
 *
 * The opcodes model a stack machine: operands are pushed/popped from the value stack.
 */
enum OpCode: int
{
    // Stack operations
    case Const       = 0;   // Push a constant (operand = index into constant pool)
    case Pop         = 1;   // Discard TOS
    case Dup         = 2;   // Duplicate TOS

    // Arithmetic / Binary
    case Add         = 10;
    case Sub         = 11;
    case Mul         = 12;
    case Div         = 13;
    case Mod         = 14;
    case Negate      = 15;  // Unary minus
    case Not         = 16;  // Logical not
    case Typeof      = 17;  // typeof — push type string
    case Exp         = 18;  // **  (exponentiation)
    case TypeofVar   = 19;  // typeof <identifier> — safe: pushes "undefined" if not defined

    // Comparison
    case Eq          = 20;  // ==
    case Neq         = 21;  // !=
    case StrictEq    = 22;  // ===
    case StrictNeq   = 23;  // !==
    case Lt          = 24;  // <
    case Lte         = 25;  // <=
    case Gt          = 26;  // >
    case Gte         = 27;  // >=

    // String concatenation (when + operates on strings)
    case Concat      = 28;

    // Variables
    case GetLocal    = 30;  // Push value of local variable (operand = name index)
    case SetLocal    = 31;  // Pop TOS and store in local (operand = name index)
    case DefineVar   = 32;  // Define a new variable in current scope (operand = name index, extra = kind)
    case GetReg      = 33;  // Push value from register file (operand = slot index)
    case SetReg      = 34;  // Pop TOS and store in register file (operand = slot index)

    // Bitwise
    case BitAnd      = 35;  // &
    case BitOr       = 36;  // |
    case BitXor      = 37;  // ^
    case BitNot      = 38;  // ~  (unary)
    case Shl         = 39;  // <<

    // Control flow
    case Jump        = 40;  // Unconditional jump (operand = target IP)
    case JumpIfFalse = 41;  // Pop TOS; jump if falsy (operand = target IP)
    case JumpIfTrue  = 42;  // Pop TOS; jump if truthy (operand = target IP)
    case JumpIfNotNullish = 43;  // Pop TOS; jump if not null/undefined (operand = target IP)
    case Shr         = 44;  // >>  (arithmetic right shift)
    case Ushr        = 45;  // >>> (unsigned right shift)
    case DeleteProp  = 46;  // Pop key, pop obj, delete obj[key], push true
    case HasProp     = 47;  // Pop obj, pop key, push (key in obj)
    case InstanceOf  = 48;  // Pop constructor, pop obj, push bool

    // Functions
    case MakeClosure = 50;  // Create closure (operand = function descriptor index)
    case Call        = 51;  // Call function (operand = arg count). TOS = [args..., callee]
    case Return      = 52;  // Return from function, TOS = return value
    case New         = 53;  // Construct (operand = arg count). Like Call but creates new object, binds `this`
    case CallSpread  = 54;  // Call with spread: TOS = JsArray of args, below = callee
    case NewSpread   = 55;  // new with spread: TOS = JsArray of args, below = callee

    // Exception handling
    case SetCatch    = 60;  // Push exception handler (operandA = catch handler IP)
    case PopCatch    = 61;  // Pop exception handler (try completed normally)
    case Throw       = 62;  // Pop TOS, throw as JS exception

    // Scope
    case PushScope   = 70;  // Create child environment (for block-scoped let/const)
    case PopScope    = 71;  // Restore parent environment

    // Arrays / Objects / Property access
    case MakeArray   = 80;  // Create array (operand = element count), pops N elements, pushes JsArray
    case GetProperty = 81;  // Pop key, pop object, push object[key]
    case SetProperty = 82;  // Pop value, pop key, pop object, set object[key]=value, push value
    case MakeObject  = 83;  // Create object (operand = property count), pops N key-value pairs, pushes JsObject
    case GetPropertyOpt = 84;  // Optional chaining: like GetProperty but pushes undefined if object is null/undefined
    case ArrayPush   = 85;  // Pop value, push onto JsArray at TOS-1
    case ArraySpread = 86;  // Pop iterable, spread elements into JsArray at TOS-1

    // Special
    case Halt        = 99;  // Stop execution
}
