<?php

declare(strict_types=1);

namespace ScriptLite\Vm;

use ScriptLite\Compiler\CompiledScript;
use ScriptLite\Compiler\FunctionDescriptor;
use ScriptLite\Compiler\OpCode;
use ScriptLite\Runtime\Environment;
use ScriptLite\Runtime\JsArray;
use ScriptLite\Runtime\JsClosure;
use ScriptLite\Runtime\JsDate;
use ScriptLite\Runtime\JsObject;
use ScriptLite\Runtime\JsRegex;
use ScriptLite\Runtime\PhpObjectProxy;
use ScriptLite\Runtime\JsUndefined;
use ScriptLite\Runtime\NativeFunction;

/**
 * Stack-based Virtual Machine for executing compiled JavaScript bytecode.
 *
 * ═══════════════════════════════════════════════════════════════════
 * WHY THIS IS FASTER THAN TREE-WALKING IN PHP (The "Magic Sauce"):
 * ═══════════════════════════════════════════════════════════════════
 *
 * 1. FLAT DISPATCH LOOP: The entire execution lives in a single while(true)
 *    loop with a match() on integer-backed enums. PHP 8's JIT compiles this
 *    into a jump table — essentially native machine code dispatch.
 *    Tree-walking requires deep recursion (PHP default stack: 256 frames)
 *    which means: vtable lookups, frame allocation, instanceof checks per node.
 *
 * 2. CACHE LOCALITY: OpCode arrays are linear in memory. The CPU prefetcher
 *    loves sequential access. AST trees are pointer-chasing across heap objects.
 *
 * 3. STACK vs RECURSION: Our value stack is a flat PHP array with integer keys.
 *    Zend Engine optimizes packed arrays as C-style vectors. Each "push" is just
 *    an offset increment — no PHP function call overhead.
 *
 * 4. JIT FRIENDLINESS: OPcache's JIT (tracing JIT in PHP 8.1+) specializes
 *    hot loops. A single dispatch loop is the ideal candidate for JIT trace
 *    compilation. Tree-walking creates many small methods that JIT can't
 *    effectively inline across the visitor pattern.
 *
 * 5. MINIMAL ALLOCATION: Instruction objects are created once at compile time.
 *    At runtime, we only allocate CallFrames (function calls) and Environment
 *    objects (scope entry). Everything else is stack manipulation.
 * ═══════════════════════════════════════════════════════════════════
 */
final class VirtualMachine
{
    /** @var mixed[] Value stack */
    private array $stack = [];
    private int $sp = 0; // stack pointer (next free slot)

    /** @var CallFrame[] */
    private array $frames = [];
    private int $frameCount = 0;

    /** @var array<array{catchIP: int, frameCount: int, sp: int, env: Environment}> */
    private array $handlers = [];

    /** Current frame (cached for hot-loop performance) */
    private CallFrame $frame;

    /** Output buffer for console.log etc. */
    private string $output = '';

    private const int MAX_FRAMES = 512;

    public function execute(CompiledScript $script, ?Environment $globalEnv = null): mixed
    {
        $env = $globalEnv ?? $this->createGlobalEnvironment();

        $this->pushFrame(
            $script->main->ops,
            $script->main->opA,
            $script->main->opB,
            $script->main->constants,
            $script->main->names,
            $env,
        );

        // Set up register file for main script
        if ($script->main->regCount > 0) {
            $this->frame->registers = array_fill(0, $script->main->regCount, JsUndefined::Value);
        }

        return $this->run();
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    // ──────────────────── The Hot Loop ────────────────────
    //
    // OPTIMIZATION: All hot variables are localized to PHP local variables.
    // PHP local variable access (direct CV slot) is ~5x faster than
    // $this->property (hash table lookup per access). Combined with inlined
    // push/pop and elimination of binaryOp closure allocations, this cuts
    // per-instruction overhead dramatically.

    private function run(): mixed
    {
        // ── Localize hot variables ──
        // The stack is shared by reference so writes here update $this->stack.
        $stack = &$this->stack;
        $sp = $this->sp;
        $frame = $this->frame;
        $ops = $frame->ops;
        $opA = $frame->opA;
        $opB = $frame->opB;
        $constants = $frame->constants;
        $names = $frame->names;
        $env = $frame->env;
        $ip = $frame->ip;
        $regs = &$frame->registers;

        while (true) {
          try {
            while (true) {
            $ci = $ip++;

            switch ($ops[$ci]) {

            // ── Constants & Stack ──
            case OpCode::Const:
                $stack[$sp++] = $constants[$opA[$ci]];
                break;
            case OpCode::Pop:
                --$sp;
                break;
            case OpCode::Dup:
                $stack[$sp] = $stack[$sp - 1];
                $sp++;
                break;

            // ── Arithmetic (inlined type guards — fast path avoids method calls) ──
            case OpCode::Add:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                if (is_int($a)) {
                    if (is_int($b)) { $stack[$sp - 1] = $a + $b; break; }
                    if (is_float($b)) { $stack[$sp - 1] = $a + $b; break; }
                } elseif (is_float($a) && (is_int($b) || is_float($b))) {
                    $stack[$sp - 1] = $a + $b; break;
                }
                // Slow path: ToPrimitive + type coercion
                if ($a instanceof JsArray || $a instanceof JsObject) { $a = $this->toJsString($a); }
                if ($b instanceof JsArray || $b instanceof JsObject) { $b = $this->toJsString($b); }
                if (is_string($a) || is_string($b)) {
                    $stack[$sp - 1] = $this->toJsString($a) . $this->toJsString($b);
                } else {
                    $stack[$sp - 1] = $this->toNumber($a) + $this->toNumber($b);
                }
                break;
            case OpCode::Sub:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                $stack[$sp - 1] = (is_int($a) || is_float($a) ? $a : $this->toNumber($a))
                                - (is_int($b) || is_float($b) ? $b : $this->toNumber($b));
                break;
            case OpCode::Mul:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                $stack[$sp - 1] = (is_int($a) || is_float($a) ? $a : $this->toNumber($a))
                                * (is_int($b) || is_float($b) ? $b : $this->toNumber($b));
                break;
            case OpCode::Div:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                $stack[$sp - 1] = $b == 0
                    ? ($a == 0 ? NAN : ($a > 0 ? INF : -INF))
                    : $a / $b;
                break;
            case OpCode::Mod:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                $stack[$sp - 1] = fmod(
                    is_int($a) || is_float($a) ? $a : $this->toNumber($a),
                    is_int($b) || is_float($b) ? $b : $this->toNumber($b),
                );
                break;
            case OpCode::Negate:
                $a = $stack[$sp - 1];
                $stack[$sp - 1] = -(is_int($a) || is_float($a) ? $a : $this->toNumber($a));
                break;
            case OpCode::Not:
                $v = $stack[$sp - 1];
                $stack[$sp - 1] = is_bool($v) ? !$v : !$this->isTruthy($v);
                break;
            case OpCode::Typeof:
                $stack[$sp - 1] = $this->jsTypeof($stack[$sp - 1]);
                break;
            case OpCode::TypeofVar:
                // Safe typeof on identifier — returns "undefined" if variable not defined
                if ($env->has($names[$opA[$ci]])) {
                    $stack[$sp++] = $this->jsTypeof($env->get($names[$opA[$ci]]));
                } else {
                    $stack[$sp++] = 'undefined';
                }
                break;
            case OpCode::Exp:
                $b = $stack[--$sp];
                $stack[$sp - 1] = $stack[$sp - 1] ** $b;
                break;

            // ── Bitwise ──
            case OpCode::BitAnd:
                $b = $stack[--$sp];
                $stack[$sp - 1] = ((int) $stack[$sp - 1]) & ((int) $b);
                break;
            case OpCode::BitOr:
                $b = $stack[--$sp];
                $stack[$sp - 1] = ((int) $stack[$sp - 1]) | ((int) $b);
                break;
            case OpCode::BitXor:
                $b = $stack[--$sp];
                $stack[$sp - 1] = ((int) $stack[$sp - 1]) ^ ((int) $b);
                break;
            case OpCode::BitNot:
                $stack[$sp - 1] = ~((int) $stack[$sp - 1]);
                break;
            case OpCode::Shl:
                $b = $stack[--$sp];
                $stack[$sp - 1] = ((int) $stack[$sp - 1]) << (((int) $b) & 0x1F);
                break;
            case OpCode::Shr:
                $b = $stack[--$sp];
                $stack[$sp - 1] = ((int) $stack[$sp - 1]) >> (((int) $b) & 0x1F);
                break;
            case OpCode::Ushr:
                $b = $stack[--$sp];
                $shift = ((int) $b) & 0x1F;
                $stack[$sp - 1] = (((int) $stack[$sp - 1]) & 0xFFFFFFFF) >> $shift;
                break;

            // ── Comparison (inlined — no closure allocation) ──
            case OpCode::Eq:
                $b = $stack[--$sp];
                $stack[$sp - 1] = $this->jsLooseEqual($stack[$sp - 1], $b);
                break;
            case OpCode::Neq:
                $b = $stack[--$sp];
                $stack[$sp - 1] = !$this->jsLooseEqual($stack[$sp - 1], $b);
                break;
            case OpCode::StrictEq:
                $b = $stack[--$sp];
                $stack[$sp - 1] = $this->strictEqual($stack[$sp - 1], $b);
                break;
            case OpCode::StrictNeq:
                $b = $stack[--$sp];
                $stack[$sp - 1] = !$this->strictEqual($stack[$sp - 1], $b);
                break;
            case OpCode::Lt:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                // JS: convert booleans to numbers, objects to NaN for relational comparison
                if (is_bool($a)) { $a = $a ? 1 : 0; }
                if (is_bool($b)) { $b = $b ? 1 : 0; }
                if (is_object($a) || is_object($b)) { $stack[$sp - 1] = false; break; }
                $stack[$sp - 1] = $a < $b;
                break;
            case OpCode::Lte:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                if (is_bool($a)) { $a = $a ? 1 : 0; }
                if (is_bool($b)) { $b = $b ? 1 : 0; }
                if (is_object($a) || is_object($b)) { $stack[$sp - 1] = false; break; }
                $stack[$sp - 1] = $a <= $b;
                break;
            case OpCode::Gt:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                if (is_bool($a)) { $a = $a ? 1 : 0; }
                if (is_bool($b)) { $b = $b ? 1 : 0; }
                if (is_object($a) || is_object($b)) { $stack[$sp - 1] = false; break; }
                $stack[$sp - 1] = $a > $b;
                break;
            case OpCode::Gte:
                $b = $stack[--$sp];
                $a = $stack[$sp - 1];
                if (is_bool($a)) { $a = $a ? 1 : 0; }
                if (is_bool($b)) { $b = $b ? 1 : 0; }
                if (is_object($a) || is_object($b)) { $stack[$sp - 1] = false; break; }
                $stack[$sp - 1] = $a >= $b;
                break;

            // ── Property tests / delete ──
            case OpCode::HasProp:
                $obj = $stack[--$sp]; // right operand (object)
                $key = (string) $stack[$sp - 1]; // left operand (key)
                if ($obj instanceof JsObject) {
                    $stack[$sp - 1] = array_key_exists($key, $obj->properties);
                } elseif ($obj instanceof JsArray) {
                    $idx = is_numeric($key) ? (int) $key : -1;
                    $stack[$sp - 1] = $idx >= 0 && $idx < count($obj->elements);
                } else {
                    $stack[$sp - 1] = false;
                }
                break;
            case OpCode::InstanceOf:
                $ctor = $stack[--$sp]; // right operand (constructor)
                $obj = $stack[$sp - 1]; // left operand (object)
                $stack[$sp - 1] = ($obj instanceof JsObject && $ctor instanceof JsClosure && $obj->constructor === $ctor);
                break;
            case OpCode::DeleteProp:
                $key = $stack[--$sp];
                $obj = $stack[--$sp];
                if ($obj instanceof JsObject) {
                    unset($obj->properties[(string) $key]);
                } elseif ($obj instanceof JsArray && is_numeric($key)) {
                    $idx = (int) $key;
                    if ($idx >= 0 && $idx < count($obj->elements)) {
                        $obj->elements[$idx] = JsUndefined::Value;
                    }
                }
                $stack[$sp++] = true;
                break;

            // ── Variables ──
            case OpCode::GetLocal:
                $stack[$sp++] = $env->get($names[$opA[$ci]]);
                break;
            case OpCode::SetLocal:
                $env->set($names[$opA[$ci]], $stack[--$sp]);
                break;
            case OpCode::DefineVar:
                $env->define($names[$opA[$ci]], $stack[--$sp], $opB[$ci] === 2);
                break;
            case OpCode::GetReg:
                $stack[$sp++] = $regs[$opA[$ci]];
                break;
            case OpCode::SetReg:
                $regs[$opA[$ci]] = $stack[--$sp];
                break;

            // ── Control Flow ──
            case OpCode::Jump:
                $ip = $opA[$ci];
                break;
            case OpCode::JumpIfFalse:
                $v = $stack[--$sp];
                if ($v === false || $v === 0 || $v === 0.0 || $v === '' || $v === null || $v === JsUndefined::Value
                    || (is_float($v) && is_nan($v))) {
                    $ip = $opA[$ci];
                }
                break;
            case OpCode::JumpIfTrue:
                $v = $stack[--$sp];
                if ($v !== false && $v !== 0 && $v !== 0.0 && $v !== '' && $v !== null && $v !== JsUndefined::Value
                    && !(is_float($v) && is_nan($v))) {
                    $ip = $opA[$ci];
                }
                break;
            case OpCode::JumpIfNotNullish:
                $v = $stack[--$sp];
                if ($v !== null && $v !== JsUndefined::Value) {
                    $ip = $opA[$ci];
                }
                break;

            // ── Exception handling ──
            case OpCode::SetCatch:
                $this->handlers[] = [
                    'catchIP' => $opA[$ci],
                    'frameCount' => $this->frameCount,
                    'sp' => $sp,
                    'env' => $env,
                ];
                break;
            case OpCode::PopCatch:
                array_pop($this->handlers);
                break;
            case OpCode::Throw:
                throw new JsThrowable($stack[--$sp]);

            // ── Scope ──
            case OpCode::PushScope:
                $env = $env->extend();
                break;
            case OpCode::PopScope:
                $env = $env->getParent();
                break;

            // ── Functions (sync locals ↔ $this, delegate, re-read) ──
            case OpCode::MakeClosure:
                $stack[$sp++] = new JsClosure($constants[$opA[$ci]], $env);
                break;

            case OpCode::Call:
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                $this->call($opA[$ci]);
                // Re-read — frame may have changed (JsClosure pushes new frame)
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::CallOpt:
                $argCount = $opA[$ci];
                $calleeIdx = $sp - $argCount - 1;
                $callee = $stack[$calleeIdx];
                if ($callee === null || $callee === JsUndefined::Value) {
                    $sp = $calleeIdx;
                    $stack[$sp++] = JsUndefined::Value;
                    break;
                }
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                $this->call($argCount);
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::New:
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                $this->doNew($opA[$ci]);
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::CallSpread:
                $argsArray = $stack[--$sp];
                $callee = $stack[--$sp];
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                if ($callee instanceof JsClosure) {
                    $this->callClosure($callee, $argsArray->elements);
                } elseif ($callee instanceof NativeFunction) {
                    $result = ($callee->callable)(...$argsArray->elements);
                    $this->push($result ?? JsUndefined::Value);
                } else {
                    throw new VmException('TypeError: ' . gettype($callee) . ' is not a function');
                }
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::CallSpreadOpt:
                $argsArray = $stack[--$sp];
                $callee = $stack[--$sp];
                if ($callee === null || $callee === JsUndefined::Value) {
                    $stack[$sp++] = JsUndefined::Value;
                    break;
                }
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                if ($callee instanceof JsClosure) {
                    $this->callClosure($callee, $argsArray->elements);
                } elseif ($callee instanceof NativeFunction) {
                    $result = ($callee->callable)(...$argsArray->elements);
                    $this->push($result ?? JsUndefined::Value);
                } else {
                    throw new VmException('TypeError: ' . gettype($callee) . ' is not a function');
                }
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::NewSpread:
                $argsArray = $stack[--$sp];
                $callee = $stack[--$sp];
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                $this->doNewWithArgs($callee, $argsArray->elements);
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            case OpCode::Return:
                $this->sp = $sp;
                $frame->ip = $ip;
                $frame->env = $env;
                $this->doReturn();
                $frame = $this->frame;
                $ops = $frame->ops;
                $opA = $frame->opA;
                $opB = $frame->opB;
                $constants = $frame->constants;
                $names = $frame->names;
                $env = $frame->env;
                $ip = $frame->ip;
                $sp = $this->sp;
                $regs = &$frame->registers;
                break;

            // ── Arrays / Objects (inlined where simple, sync for property ops) ──
            case OpCode::MakeArray:
                $count = $opA[$ci];
                $elements = [];
                for ($j = $count - 1; $j >= 0; $j--) {
                    $elements[$j] = $stack[--$sp];
                }
                ksort($elements);
                $stack[$sp++] = new JsArray($elements);
                break;

            case OpCode::MakeObject:
                $count = $opA[$ci];
                $pairs = [];
                for ($j = 0; $j < $count; $j++) {
                    $value = $stack[--$sp];
                    $key = $stack[--$sp];
                    $pairs[] = [(string) $key, $value];
                }
                $properties = [];
                for ($j = count($pairs) - 1; $j >= 0; $j--) {
                    $properties[$pairs[$j][0]] = $pairs[$j][1];
                }
                $stack[$sp++] = new JsObject($properties);
                break;

            case OpCode::GetProperty:
                $this->sp = $sp;
                $frame->env = $env;
                $this->getProperty();
                $sp = $this->sp;
                break;

            case OpCode::GetPropertyOpt:
                $this->sp = $sp;
                $frame->env = $env;
                $this->getPropertyOpt();
                $sp = $this->sp;
                break;

            case OpCode::SetProperty:
                $this->sp = $sp;
                $this->setProperty();
                $sp = $this->sp;
                break;

            case OpCode::ArrayPush:
                $val = $stack[--$sp];
                $stack[$sp - 1]->elements[] = $val;
                break;

            case OpCode::ArraySpread:
                $iterable = $stack[--$sp];
                $arr = $stack[$sp - 1];
                if ($iterable instanceof JsArray) {
                    foreach ($iterable->elements as $el) {
                        $arr->elements[] = $el;
                    }
                } elseif (is_string($iterable)) {
                    $len = strlen($iterable);
                    for ($j = 0; $j < $len; $j++) {
                        $arr->elements[] = $iterable[$j];
                    }
                }
                break;

            case OpCode::Halt:
                goto halt;

            default:
                throw new VmException("Unknown opcode: {$ops[$ci]->name}");
            }
            }
          } catch (\Throwable $e) {
            // Sync localized state back to frame
            $this->sp = $sp;
            $frame->ip = $ip;
            $frame->env = $env;

            $thrown = $e instanceof JsThrowable ? $e->value : $e->getMessage();

            if (empty($this->handlers)) {
                if ($e instanceof JsThrowable) {
                    throw new VmException("Uncaught: " . $this->toJsString($thrown));
                }
                throw $e;
            }

            $this->handleThrow($thrown);

            // Reload frame state (handler may have unwound frames)
            $frame = $this->frame;
            $ops = $frame->ops;
            $opA = $frame->opA;
            $opB = $frame->opB;
            $constants = $frame->constants;
            $names = $frame->names;
            $env = $frame->env;
            $ip = $frame->ip;
            $sp = $this->sp;
            $regs = &$frame->registers;
          }
        }

        halt:
        $this->sp = $sp;
        return $sp > 0 ? $stack[--$this->sp] : JsUndefined::Value;
    }

    /**
     * Execute a single instruction. Used by invokeFunction() for re-entrant
     * execution (e.g., array callback methods like map/filter).
     */
    private function executeInstruction(OpCode $op, int $a, int $b): void
    {
        match ($op) {
            // ── Constants & Stack ──
            OpCode::Const => $this->push($this->frame->constants[$a]),
            OpCode::Pop   => $this->pop(),
            OpCode::Dup   => $this->push($this->stack[$this->sp - 1]),

            // ── Arithmetic ──
            OpCode::Add    => $this->binaryAdd(),
            OpCode::Sub    => $this->binaryOp(fn($x, $y) => $x - $y),
            OpCode::Mul    => $this->binaryOp(fn($x, $y) => $x * $y),
            OpCode::Div    => $this->binaryOp(fn($x, $y) => $y == 0 ? ($x == 0 ? NAN : ($x > 0 ? INF : -INF)) : $x / $y),
            OpCode::Mod    => $this->binaryOp(fn($x, $y) => fmod($x, $y)),
            OpCode::Negate => $this->push(-$this->pop()),
            OpCode::Not    => $this->push(!$this->isTruthy($this->pop())),
            OpCode::Typeof => $this->push($this->jsTypeof($this->pop())),
            OpCode::Exp    => $this->binaryOp(fn($x, $y) => $x ** $y),

            // ── Bitwise ──
            OpCode::BitAnd => $this->binaryOp(fn($x, $y) => ((int) $x) & ((int) $y)),
            OpCode::BitOr  => $this->binaryOp(fn($x, $y) => ((int) $x) | ((int) $y)),
            OpCode::BitXor => $this->binaryOp(fn($x, $y) => ((int) $x) ^ ((int) $y)),
            OpCode::BitNot => $this->push(~((int) $this->pop())),
            OpCode::Shl    => $this->binaryOp(fn($x, $y) => ((int) $x) << (((int) $y) & 0x1F)),
            OpCode::Shr    => $this->binaryOp(fn($x, $y) => ((int) $x) >> (((int) $y) & 0x1F)),
            OpCode::Ushr   => $this->binaryOp(fn($x, $y) => (((int) $x) & 0xFFFFFFFF) >> (((int) $y) & 0x1F)),

            // ── Comparison ──
            OpCode::Eq       => $this->binaryOp(fn($x, $y) => $x == $y),
            OpCode::Neq      => $this->binaryOp(fn($x, $y) => $x != $y),
            OpCode::StrictEq => $this->binaryOp(fn($x, $y) => $this->strictEqual($x, $y)),
            OpCode::StrictNeq => $this->binaryOp(fn($x, $y) => !$this->strictEqual($x, $y)),
            OpCode::Lt       => $this->binaryOp(fn($x, $y) => $x < $y),
            OpCode::Lte      => $this->binaryOp(fn($x, $y) => $x <= $y),
            OpCode::Gt       => $this->binaryOp(fn($x, $y) => $x > $y),
            OpCode::Gte      => $this->binaryOp(fn($x, $y) => $x >= $y),

            // ── Property tests / delete ──
            OpCode::HasProp    => $this->hasProp(),
            OpCode::InstanceOf => $this->instanceOfCheck(),
            OpCode::DeleteProp => $this->deleteProp(),

            // ── Variables ──
            OpCode::GetLocal  => $this->push($this->frame->env->get($this->frame->names[$a])),
            OpCode::SetLocal  => $this->frame->env->set($this->frame->names[$a], $this->pop()),
            OpCode::DefineVar => $this->defineVar($a, $b),
            OpCode::GetReg    => $this->push($this->frame->registers[$a]),
            OpCode::SetReg    => $this->frame->registers[$a] = $this->pop(),

            // ── Control Flow ──
            OpCode::Jump        => $this->frame->ip = $a,
            OpCode::JumpIfFalse => $this->jumpIfFalse($a),
            OpCode::JumpIfTrue  => $this->jumpIfTrue($a),
            OpCode::JumpIfNotNullish => $this->jumpIfNotNullish($a),

            // ── Exception handling ──
            OpCode::SetCatch => $this->handlers[] = [
                'catchIP' => $a,
                'frameCount' => $this->frameCount,
                'sp' => $this->sp,
                'env' => $this->frame->env,
            ],
            OpCode::PopCatch => array_pop($this->handlers),
            OpCode::Throw    => throw new JsThrowable($this->pop()),

            // ── Functions ──
            OpCode::MakeClosure => $this->makeClosure($a),
            OpCode::Call        => $this->call($a),
            OpCode::New         => $this->doNew($a),
            OpCode::Return      => $this->doReturn(),
            OpCode::CallSpread  => $this->callSpread(),
            OpCode::CallOpt     => $this->callOpt($a),
            OpCode::CallSpreadOpt => $this->callSpreadOpt(),
            OpCode::NewSpread   => $this->newSpread(),

            // ── Scope ──
            OpCode::PushScope => $this->frame->env = $this->frame->env->extend(),
            OpCode::PopScope  => $this->frame->env = $this->frame->env->getParent(),

            // ── Arrays / Objects / Properties ──
            OpCode::MakeArray   => $this->makeArray($a),
            OpCode::MakeObject  => $this->makeObject($a),
            OpCode::GetProperty    => $this->getProperty(),
            OpCode::GetPropertyOpt => $this->getPropertyOpt(),
            OpCode::SetProperty    => $this->setProperty(),
            OpCode::ArrayPush   => $this->arrayPush(),
            OpCode::ArraySpread => $this->arraySpread(),

            default => throw new VmException("Unknown opcode: {$op->name}"),
        };
    }

    /**
     * Invoke a JS function (closure or native) synchronously and return the result.
     *
     * This enables re-entrant execution: native functions (like Array.map's callback
     * invoker) can call back into the VM to execute JS closures, then collect results.
     */
    public function invokeFunction(mixed $callee, array $args): mixed
    {
        if ($callee instanceof NativeFunction) {
            $result = ($callee->callable)(...$args);
            return $result ?? JsUndefined::Value;
        }

        if ($callee instanceof JsClosure) {
            $desc = $callee->descriptor;
            $callEnv = $callee->capturedEnv->extend();

            $ac = count($args);
            $paramCount = count($desc->params);
            for ($i = 0; $i < $paramCount; $i++) {
                if (!isset($desc->paramSlots[$i]) || $desc->paramSlots[$i] < 0) {
                    $callEnv->define($desc->params[$i], $i < $ac ? $args[$i] : JsUndefined::Value);
                }
            }
            if ($desc->restParam !== null && $desc->restParamSlot < 0) {
                $callEnv->define($desc->restParam, new JsArray(array_slice($args, $paramCount)));
            }

            $targetFrameCount = $this->frameCount;
            $this->pushFrame($desc->ops, $desc->opA, $desc->opB, $desc->constants, $desc->names, $callEnv);

            // Set up register file and bind register-allocated parameters
            if ($desc->regCount > 0) {
                $this->frame->registers = array_fill(0, $desc->regCount, JsUndefined::Value);
                for ($i = 0; $i < $paramCount; $i++) {
                    if (isset($desc->paramSlots[$i]) && $desc->paramSlots[$i] >= 0) {
                        $this->frame->registers[$desc->paramSlots[$i]] = $i < $ac ? $args[$i] : JsUndefined::Value;
                    }
                }
                if ($desc->restParam !== null && $desc->restParamSlot >= 0) {
                    $this->frame->registers[$desc->restParamSlot] = new JsArray(array_slice($args, $paramCount));
                }
            }

            // Run until this function returns (frameCount drops back to target)
            while ($this->frameCount > $targetFrameCount) {
                try {
                    while ($this->frameCount > $targetFrameCount) {
                        $ci = $this->frame->ip++;

                        if ($this->frame->ops[$ci] === OpCode::Halt) {
                            break 2;
                        }

                        $this->executeInstruction($this->frame->ops[$ci], $this->frame->opA[$ci], $this->frame->opB[$ci]);
                    }
                } catch (\Throwable $e) {
                    $thrown = $e instanceof JsThrowable ? $e->value : $e->getMessage();
                    if (empty($this->handlers) || end($this->handlers)['frameCount'] < $targetFrameCount) {
                        throw $e; // No handler in this call scope, propagate
                    }
                    $this->handleThrow($thrown);
                }
            }

            return $this->pop();
        }

        throw new VmException('TypeError: ' . gettype($callee) . ' is not a function');
    }

    // ──────────────────── Stack Operations ────────────────────

    private function push(mixed $value): void
    {
        $this->stack[$this->sp++] = $value;
    }

    private function pop(): mixed
    {
        return $this->stack[--$this->sp];
    }

    // ──────────────────── Binary Ops ────────────────────

    private function binaryOp(\Closure $fn): void
    {
        $b = $this->pop();
        $a = $this->pop();
        $this->push($fn($a, $b));
    }

    /**
     * JS + semantics: if either operand is a string, concatenate; else numeric add.
     */
    private function binaryAdd(): void
    {
        $b = $this->pop();
        $a = $this->pop();

        if (is_string($a) || is_string($b)) {
            $this->push($this->toJsString($a) . $this->toJsString($b));
        } else {
            $this->push($this->toNumber($a) + $this->toNumber($b));
        }
    }

    // ──────────────────── Variable Ops ────────────────────

    private function defineVar(int $nameIdx, int $kind): void
    {
        $name  = $this->frame->names[$nameIdx];
        $value = $this->pop();
        $isConst = ($kind === 2);
        $this->frame->env->define($name, $value, $isConst);
    }

    // ──────────────────── Control Flow ────────────────────

    private function jumpIfFalse(int $target): void
    {
        $val = $this->pop();
        if (!$this->isTruthy($val)) {
            $this->frame->ip = $target;
        }
    }

    private function jumpIfTrue(int $target): void
    {
        $val = $this->pop();
        if ($this->isTruthy($val)) {
            $this->frame->ip = $target;
        }
    }

    private function jumpIfNotNullish(int $target): void
    {
        $val = $this->pop();
        if ($val !== null && $val !== JsUndefined::Value) {
            $this->frame->ip = $target;
        }
    }

    // ──────────────────── Functions ────────────────────

    private function makeClosure(int $descIdx): void
    {
        /** @var FunctionDescriptor $desc */
        $desc = $this->frame->constants[$descIdx];
        $this->push(new JsClosure($desc, $this->frame->env));
    }

    private function call(int $argCount): void
    {
        // Stack: [callee, arg0, arg1, ..., argN-1] (callee is below args)
        // Get callee from stack
        $calleeIdx = $this->sp - $argCount - 1;
        $callee    = $this->stack[$calleeIdx];

        // Collect arguments
        $args = [];
        for ($i = 0; $i < $argCount; $i++) {
            $args[] = $this->stack[$calleeIdx + 1 + $i];
        }

        // Reset stack to before callee
        $this->sp = $calleeIdx;

        if ($callee instanceof JsClosure) {
            $this->callClosure($callee, $args);
        } elseif ($callee instanceof NativeFunction) {
            $result = ($callee->callable)(...$args);
            $this->push($result ?? JsUndefined::Value);
        } else {
            throw new VmException('TypeError: ' . gettype($callee) . ' is not a function');
        }
    }

    private function callClosure(JsClosure $closure, array $args): void
    {
        if ($this->frameCount >= self::MAX_FRAMES) {
            throw new VmException('RangeError: Maximum call stack size exceeded');
        }

        $desc = $closure->descriptor;

        // Create new environment extending the closure's captured environment
        $callEnv = $closure->capturedEnv->extend();

        // Bind environment-allocated parameters
        $argCount = count($args);
        $paramCount = count($desc->params);
        for ($i = 0; $i < $paramCount; $i++) {
            if (!isset($desc->paramSlots[$i]) || $desc->paramSlots[$i] < 0) {
                $callEnv->define($desc->params[$i], $i < $argCount ? $args[$i] : JsUndefined::Value);
            }
        }

        // Bind rest parameter (env-allocated)
        if ($desc->restParam !== null && $desc->restParamSlot < 0) {
            $callEnv->define($desc->restParam, new JsArray(array_slice($args, $paramCount)));
        }

        $this->pushFrame($desc->ops, $desc->opA, $desc->opB, $desc->constants, $desc->names, $callEnv);

        // Set up register file and bind register-allocated parameters
        if ($desc->regCount > 0) {
            $this->frame->registers = array_fill(0, $desc->regCount, JsUndefined::Value);
            for ($i = 0; $i < $paramCount; $i++) {
                if (isset($desc->paramSlots[$i]) && $desc->paramSlots[$i] >= 0) {
                    $this->frame->registers[$desc->paramSlots[$i]] = $i < $argCount ? $args[$i] : JsUndefined::Value;
                }
            }
            // Register-allocated rest param
            if ($desc->restParam !== null && $desc->restParamSlot >= 0) {
                $this->frame->registers[$desc->restParamSlot] = new JsArray(array_slice($args, $paramCount));
            }
        }
    }

    private function doNew(int $argCount): void
    {
        $calleeIdx = $this->sp - $argCount - 1;
        $callee    = $this->stack[$calleeIdx];

        $args = [];
        for ($i = 0; $i < $argCount; $i++) {
            $args[] = $this->stack[$calleeIdx + 1 + $i];
        }
        $this->sp = $calleeIdx;

        if ($callee instanceof NativeFunction) {
            // Native constructors return the new object directly
            $result = ($callee->callable)(...$args);
            $this->push($result ?? JsUndefined::Value);
        } elseif ($callee instanceof JsClosure) {
            if ($this->frameCount >= self::MAX_FRAMES) {
                throw new VmException('RangeError: Maximum call stack size exceeded');
            }
            $desc = $callee->descriptor;
            $newObj = new JsObject([], $callee);
            $callEnv = $callee->capturedEnv->extend();
            $callEnv->define('this', $newObj);
            $ac = count($args);
            $paramCount = count($desc->params);
            for ($i = 0; $i < $paramCount; $i++) {
                if (!isset($desc->paramSlots[$i]) || $desc->paramSlots[$i] < 0) {
                    $callEnv->define($desc->params[$i], $i < $ac ? $args[$i] : JsUndefined::Value);
                }
            }
            if ($desc->restParam !== null && $desc->restParamSlot < 0) {
                $callEnv->define($desc->restParam, new JsArray(array_slice($args, $paramCount)));
            }
            $this->pushFrame($desc->ops, $desc->opA, $desc->opB, $desc->constants, $desc->names, $callEnv, $newObj);

            // Set up register file and bind register-allocated parameters
            if ($desc->regCount > 0) {
                $this->frame->registers = array_fill(0, $desc->regCount, JsUndefined::Value);
                for ($i = 0; $i < $paramCount; $i++) {
                    if (isset($desc->paramSlots[$i]) && $desc->paramSlots[$i] >= 0) {
                        $this->frame->registers[$desc->paramSlots[$i]] = $i < $ac ? $args[$i] : JsUndefined::Value;
                    }
                }
                if ($desc->restParam !== null && $desc->restParamSlot >= 0) {
                    $this->frame->registers[$desc->restParamSlot] = new JsArray(array_slice($args, $paramCount));
                }
            }
        } else {
            throw new VmException('TypeError: ' . gettype($callee) . ' is not a constructor');
        }
    }

    private function doReturn(): void
    {
        $returnValue = $this->pop();
        $constructTarget = $this->frame->constructTarget;

        // Restore stack to frame's base
        $this->sp = $this->frame->stackBase;

        $this->popFrame();

        // JS constructor return semantics:
        // If called with `new` and return value is not an object, use the constructed object
        if ($constructTarget !== null) {
            if ($returnValue instanceof JsObject || $returnValue instanceof JsArray || $returnValue instanceof JsDate || $returnValue instanceof JsRegex) {
                $this->push($returnValue);
            } else {
                $this->push($constructTarget);
            }
        } else {
            $this->push($returnValue);
        }

        // If we've returned from the very last frame, signal halt
        if ($this->frameCount === 0) {
            // Push a Halt instruction virtually
            $this->frame = new CallFrame(
                [OpCode::Halt],
                [0],
                [0],
                [],
                [],
                new Environment(),
                $this->sp,
            );
            $this->frame->ip = 0;
        }
    }

    private function callSpread(): void
    {
        $argsArray = $this->pop();
        $callee = $this->pop();

        if ($callee instanceof JsClosure) {
            $this->callClosure($callee, $argsArray->elements);
        } elseif ($callee instanceof NativeFunction) {
            $result = ($callee->callable)(...$argsArray->elements);
            $this->push($result ?? JsUndefined::Value);
        } else {
            throw new VmException('TypeError: ' . gettype($callee) . ' is not a function');
        }
    }

    private function callOpt(int $argCount): void
    {
        $calleeIdx = $this->sp - $argCount - 1;
        $callee = $this->stack[$calleeIdx];
        if ($callee === null || $callee === JsUndefined::Value) {
            $this->sp = $calleeIdx;
            $this->push(JsUndefined::Value);
            return;
        }
        $this->call($argCount);
    }

    private function callSpreadOpt(): void
    {
        $argsArray = $this->pop();
        $callee = $this->pop();
        if ($callee === null || $callee === JsUndefined::Value) {
            $this->push(JsUndefined::Value);
            return;
        }
        // Re-push and delegate
        $this->push($callee);
        $this->push($argsArray);
        $this->callSpread();
    }

    private function newSpread(): void
    {
        $argsArray = $this->pop();
        $callee = $this->pop();
        $this->doNewWithArgs($callee, $argsArray->elements);
    }

    private function doNewWithArgs(mixed $callee, array $args): void
    {
        if ($callee instanceof NativeFunction) {
            $result = ($callee->callable)(...$args);
            $this->push($result ?? JsUndefined::Value);
        } elseif ($callee instanceof JsClosure) {
            if ($this->frameCount >= self::MAX_FRAMES) {
                throw new VmException('RangeError: Maximum call stack size exceeded');
            }
            $desc = $callee->descriptor;
            $newObj = new JsObject([], $callee);
            $callEnv = $callee->capturedEnv->extend();
            $callEnv->define('this', $newObj);
            $ac = count($args);
            $paramCount = count($desc->params);
            for ($i = 0; $i < $paramCount; $i++) {
                if (!isset($desc->paramSlots[$i]) || $desc->paramSlots[$i] < 0) {
                    $callEnv->define($desc->params[$i], $i < $ac ? $args[$i] : JsUndefined::Value);
                }
            }
            if ($desc->restParam !== null && $desc->restParamSlot < 0) {
                $callEnv->define($desc->restParam, new JsArray(array_slice($args, $paramCount)));
            }
            $this->pushFrame($desc->ops, $desc->opA, $desc->opB, $desc->constants, $desc->names, $callEnv, $newObj);
            if ($desc->regCount > 0) {
                $this->frame->registers = array_fill(0, $desc->regCount, JsUndefined::Value);
                for ($i = 0; $i < $paramCount; $i++) {
                    if (isset($desc->paramSlots[$i]) && $desc->paramSlots[$i] >= 0) {
                        $this->frame->registers[$desc->paramSlots[$i]] = $i < $ac ? $args[$i] : JsUndefined::Value;
                    }
                }
                if ($desc->restParam !== null && $desc->restParamSlot >= 0) {
                    $this->frame->registers[$desc->restParamSlot] = new JsArray(array_slice($args, $paramCount));
                }
            }
        } else {
            throw new VmException('TypeError: ' . gettype($callee) . ' is not a constructor');
        }
    }

    private function arrayPush(): void
    {
        $val = $this->pop();
        $this->stack[$this->sp - 1]->elements[] = $val;
    }

    private function arraySpread(): void
    {
        $iterable = $this->pop();
        $arr = $this->stack[$this->sp - 1];
        if ($iterable instanceof JsArray) {
            foreach ($iterable->elements as $el) {
                $arr->elements[] = $el;
            }
        } elseif (is_string($iterable)) {
            $len = strlen($iterable);
            for ($i = 0; $i < $len; $i++) {
                $arr->elements[] = $iterable[$i];
            }
        }
    }

    private function hasProp(): void
    {
        $obj = $this->pop(); // right operand
        $key = (string) $this->pop(); // left operand
        if ($obj instanceof JsObject) {
            $this->push(array_key_exists($key, $obj->properties));
        } elseif ($obj instanceof JsArray) {
            $idx = is_numeric($key) ? (int) $key : -1;
            $this->push($idx >= 0 && $idx < count($obj->elements));
        } else {
            $this->push(false);
        }
    }

    private function instanceOfCheck(): void
    {
        $ctor = $this->pop();
        $obj = $this->pop();
        $this->push($obj instanceof JsObject && $ctor instanceof JsClosure && $obj->constructor === $ctor);
    }

    private function deleteProp(): void
    {
        $key = $this->pop();
        $obj = $this->pop();
        if ($obj instanceof JsObject) {
            unset($obj->properties[(string) $key]);
        } elseif ($obj instanceof JsArray && is_numeric($key)) {
            $idx = (int) $key;
            if ($idx >= 0 && $idx < count($obj->elements)) {
                $obj->elements[$idx] = JsUndefined::Value;
            }
        }
        $this->push(true);
    }

    // ──────────────────── Frame Management ────────────────────

    private function pushFrame(array $ops, array $opA, array $opB, array $constants, array $names, Environment $env, ?JsObject $constructTarget = null): void
    {
        if (isset($this->frames[$this->frameCount])) {
            $frame = $this->frames[$this->frameCount];
            $frame->reset($ops, $opA, $opB, $constants, $names, $env, $this->sp, $constructTarget);
        } else {
            $frame = new CallFrame($ops, $opA, $opB, $constants, $names, $env, $this->sp, $constructTarget);
            $this->frames[$this->frameCount] = $frame;
        }
        $this->frameCount++;
        $this->frame = $frame;
    }

    private function popFrame(): void
    {
        $this->frameCount--;
        if ($this->frameCount > 0) {
            $this->frame = $this->frames[$this->frameCount - 1];
        }
    }

    /**
     * Unwind to the nearest exception handler, restore VM state, and jump to catch IP.
     */
    private function handleThrow(mixed $value): void
    {
        $handler = array_pop($this->handlers);

        // Unwind frames if throw happened inside a called function
        while ($this->frameCount > $handler['frameCount']) {
            $this->popFrame();
        }

        // Restore stack to pre-try state
        $this->sp = $handler['sp'];

        // Push exception value (catch handler will pop it via SetLocal)
        $this->stack[$this->sp++] = $value;

        // Restore environment and jump to catch handler
        $this->frame->env = $handler['env'];
        $this->frame->ip = $handler['catchIP'];
    }

    // ──────────────────── Array / Property Operations ────────────────────

    private function makeArray(int $count): void
    {
        $elements = [];
        // Pop elements in reverse (first pushed = first element)
        for ($i = $count - 1; $i >= 0; $i--) {
            $elements[$i] = $this->pop();
        }
        ksort($elements);
        $this->push(new JsArray($elements));
    }

    private function makeObject(int $count): void
    {
        $pairs = [];
        // Pop key-value pairs in reverse (value on top, then key)
        for ($i = 0; $i < $count; $i++) {
            $value = $this->pop();
            $key = $this->pop();
            $pairs[] = [(string) $key, $value];
        }
        // Reverse so first property is set first (later duplicates overwrite — JS semantics)
        $properties = [];
        for ($i = count($pairs) - 1; $i >= 0; $i--) {
            $properties[$pairs[$i][0]] = $pairs[$i][1];
        }
        $this->push(new JsObject($properties));
    }

    private function getProperty(): void
    {
        $key = $this->pop();
        $obj = $this->pop();
        if ($obj === null || $obj === JsUndefined::Value) {
            $type = $obj === null ? 'null' : 'undefined';
            throw new VmException("TypeError: Cannot read properties of {$type} (reading '{$key}')");
        }
        $this->resolveProperty($obj, $key);
    }

    private function getPropertyOpt(): void
    {
        $key = $this->pop();
        $obj = $this->pop();

        if ($obj === null || $obj === JsUndefined::Value) {
            $this->push(JsUndefined::Value);
            return;
        }

        $this->resolveProperty($obj, $key);
    }

    private function resolveProperty(mixed $obj, mixed $key): void
    {
        if ($obj instanceof JsArray) {
            $vm = $this;
            $invoker = static fn(mixed $fn, array $a): mixed => $vm->invokeFunction($fn, $a);
            $this->push($obj->get($key, $invoker));
        } elseif ($obj instanceof JsObject) {
            $vm = $this;
            $invoker = static fn(mixed $fn, array $a): mixed => $vm->invokeFunction($fn, $a);
            $this->push($obj->get($key, $invoker));
        } elseif ($obj instanceof JsDate) {
            $this->push($obj->get($key));
        } elseif ($obj instanceof JsRegex) {
            $this->push($obj->get((string) $key));
        } elseif ($obj instanceof PhpObjectProxy) {
            $this->push($obj->get((string) $key));
        } elseif ($obj instanceof NativeFunction) {
            // Function-as-object: e.g. Date.now, Date.parse
            $this->push($obj->properties[(string) $key] ?? JsUndefined::Value);
        } elseif (is_string($obj)) {
            if ($key === 'length') {
                $this->push(mb_strlen($obj));
            } else {
                $vm = $this;
                $invoker = static fn(mixed $fn, array $a): mixed => $vm->invokeFunction($fn, $a);
                $this->push($this->getStringMethod($obj, (string) $key, $invoker));
            }
        } elseif (is_int($obj) || is_float($obj)) {
            $this->push($this->getNumberMethod($obj, (string) $key));
        } else {
            $this->push(JsUndefined::Value);
        }
    }

    private function getStringMethod(string $str, string $name, ?\Closure $invoker = null): mixed
    {
        return match ($name) {
            'charAt' => new NativeFunction('charAt', function (mixed $index = 0) use ($str) {
                $i = (int) $index;
                return ($i >= 0 && $i < mb_strlen($str)) ? mb_substr($str, $i, 1) : '';
            }),
            'charCodeAt' => new NativeFunction('charCodeAt', function (mixed $index = 0) use ($str) {
                $i = (int) $index;
                if ($i < 0 || $i >= mb_strlen($str)) {
                    return NAN;
                }
                return (float) mb_ord(mb_substr($str, $i, 1));
            }),
            'indexOf' => new NativeFunction('indexOf', function (mixed $search, mixed $fromIndex = 0) use ($str) {
                $pos = mb_strpos($str, (string) $search, max(0, (int) $fromIndex));
                return $pos === false ? -1 : $pos;
            }),
            'lastIndexOf' => new NativeFunction('lastIndexOf', function (mixed $search) use ($str) {
                $pos = mb_strrpos($str, (string) $search);
                return $pos === false ? -1 : $pos;
            }),
            'includes' => new NativeFunction('includes', function (mixed $search, mixed $fromIndex = 0) use ($str) {
                return mb_strpos($str, (string) $search, max(0, (int) $fromIndex)) !== false;
            }),
            'startsWith' => new NativeFunction('startsWith', function (mixed $prefix, mixed $pos = 0) use ($str) {
                $p = (int) $pos;
                return str_starts_with(mb_substr($str, $p), (string) $prefix);
            }),
            'endsWith' => new NativeFunction('endsWith', function (mixed $suffix, mixed $endPos = null) use ($str) {
                $s = (string) $suffix;
                $sub = $endPos !== null && $endPos !== JsUndefined::Value
                    ? mb_substr($str, 0, (int) $endPos)
                    : $str;
                return str_ends_with($sub, $s);
            }),
            'slice' => new NativeFunction('slice', function (mixed $start = 0, mixed $end = null) use ($str) {
                $len = mb_strlen($str);
                $s = (int) $start;
                if ($s < 0) {
                    $s = max($len + $s, 0);
                }
                if ($end === null || $end === JsUndefined::Value) {
                    return mb_substr($str, $s);
                }
                $e = (int) $end;
                if ($e < 0) {
                    $e = max($len + $e, 0);
                }
                if ($e <= $s) {
                    return '';
                }
                return mb_substr($str, $s, $e - $s);
            }),
            'substring' => new NativeFunction('substring', function (mixed $start = 0, mixed $end = null) use ($str) {
                $len = mb_strlen($str);
                $s = max(0, min((int) $start, $len));
                $e = ($end === null || $end === JsUndefined::Value) ? $len : max(0, min((int) $end, $len));
                if ($s > $e) {
                    [$s, $e] = [$e, $s];
                }
                return mb_substr($str, $s, $e - $s);
            }),
            'toUpperCase' => new NativeFunction('toUpperCase', fn() => mb_strtoupper($str)),
            'toLowerCase' => new NativeFunction('toLowerCase', fn() => mb_strtolower($str)),
            'trim' => new NativeFunction('trim', fn() => trim($str)),
            'trimStart' => new NativeFunction('trimStart', fn() => ltrim($str)),
            'trimEnd' => new NativeFunction('trimEnd', fn() => rtrim($str)),
            'split' => new NativeFunction('split', function (mixed $separator = null, mixed $limit = null) use ($str) {
                if ($separator === null || $separator === JsUndefined::Value) {
                    return new JsArray([$str]);
                }
                if ($separator instanceof JsRegex) {
                    $lim = ($limit !== null && $limit !== JsUndefined::Value) ? (int) $limit : -1;
                    $parts = preg_split($separator->toPcre(), $str, $lim);
                    return new JsArray($parts !== false ? $parts : [$str]);
                }
                $sep = (string) $separator;
                if ($sep === '') {
                    $chars = mb_str_split($str);
                    if ($limit !== null && $limit !== JsUndefined::Value) {
                        $chars = array_slice($chars, 0, (int) $limit);
                    }
                    return new JsArray($chars);
                }
                $parts = explode($sep, $str);
                if ($limit !== null && $limit !== JsUndefined::Value) {
                    $parts = array_slice($parts, 0, (int) $limit);
                }
                return new JsArray($parts);
            }),
            'replace' => new NativeFunction('replace', function (mixed $search, mixed $replacement) use ($str, $invoker) {
                $isCallback = $replacement instanceof JsClosure || $replacement instanceof NativeFunction || is_callable($replacement);
                if ($search instanceof JsRegex) {
                    $pcre = $search->toPcre();
                    if ($isCallback && $invoker !== null) {
                        $limit = $search->isGlobal() ? -1 : 1;
                        return preg_replace_callback($pcre, function (array $m) use ($replacement, $invoker) {
                            return (string) $invoker($replacement, $m);
                        }, $str, $limit) ?? $str;
                    }
                    $r = (string) $replacement;
                    if ($search->isGlobal()) {
                        return preg_replace($pcre, $r, $str) ?? $str;
                    }
                    return preg_replace($pcre, $r, $str, 1) ?? $str;
                }
                $s = (string) $search;
                if ($isCallback && $invoker !== null) {
                    $pos = mb_strpos($str, $s);
                    if ($pos === false) {
                        return $str;
                    }
                    $r = (string) $invoker($replacement, [$s, $pos, $str]);
                    return mb_substr($str, 0, $pos) . $r . mb_substr($str, $pos + mb_strlen($s));
                }
                $r = (string) $replacement;
                $pos = mb_strpos($str, $s);
                if ($pos === false) {
                    return $str;
                }
                return mb_substr($str, 0, $pos) . $r . mb_substr($str, $pos + mb_strlen($s));
            }),
            'replaceAll' => new NativeFunction('replaceAll', function (mixed $search, mixed $replacement) use ($str) {
                return str_replace((string) $search, (string) $replacement, $str);
            }),
            'at' => new NativeFunction('at', function (mixed $index = 0) use ($str) {
                $i = (int) $index;
                $len = mb_strlen($str);
                if ($i < 0) {
                    $i += $len;
                }
                if ($i < 0 || $i >= $len) {
                    return JsUndefined::Value;
                }
                return mb_substr($str, $i, 1);
            }),
            'repeat' => new NativeFunction('repeat', function (mixed $count = 0) use ($str) {
                $n = (int) $count;
                return $n > 0 ? str_repeat($str, $n) : '';
            }),
            'padStart' => new NativeFunction('padStart', function (mixed $targetLength, mixed $padString = ' ') use ($str) {
                $target = (int) $targetLength;
                $pad = (string) $padString;
                $len = mb_strlen($str);
                if ($len >= $target || $pad === '') {
                    return $str;
                }
                $needed = $target - $len;
                $repeated = str_repeat($pad, (int) ceil($needed / mb_strlen($pad)));
                return mb_substr($repeated, 0, $needed) . $str;
            }),
            'padEnd' => new NativeFunction('padEnd', function (mixed $targetLength, mixed $padString = ' ') use ($str) {
                $target = (int) $targetLength;
                $pad = (string) $padString;
                $len = mb_strlen($str);
                if ($len >= $target || $pad === '') {
                    return $str;
                }
                $needed = $target - $len;
                $repeated = str_repeat($pad, (int) ceil($needed / mb_strlen($pad)));
                return $str . mb_substr($repeated, 0, $needed);
            }),
            'concat' => new NativeFunction('concat', function () use ($str) {
                $result = $str;
                foreach (func_get_args() as $arg) {
                    $result .= (string) $arg;
                }
                return $result;
            }),
            'match' => new NativeFunction('match', function (mixed $regex) use ($str) {
                if (!($regex instanceof JsRegex)) {
                    return null;
                }
                $pcre = $regex->toPcre();
                if ($regex->isGlobal()) {
                    if (preg_match_all($pcre, $str, $matches) > 0) {
                        return new JsArray($matches[0]);
                    }
                    return null;
                }
                if (preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE) === 1) {
                    $index = $m[0][1];
                    $matchValues = array_map(fn($v) => $v[0], $m);
                    $result = new JsArray($matchValues);
                    $result->properties['index'] = $index;
                    $result->properties['input'] = $str;
                    return $result;
                }
                return null;
            }),
            'matchAll' => new NativeFunction('matchAll', function (mixed $regex) use ($str) {
                if (!($regex instanceof JsRegex)) {
                    return new JsArray([]);
                }
                $pcre = $regex->toPcre();
                $results = [];
                if (preg_match_all($pcre, $str, $allMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($allMatches as $match) {
                        $index = $match[0][1];
                        $matchValues = array_map(fn($v) => $v[0], $match);
                        $entry = new JsArray($matchValues);
                        $entry->properties['index'] = $index;
                        $entry->properties['input'] = $str;
                        $results[] = $entry;
                    }
                }
                return new JsArray($results);
            }),
            'search' => new NativeFunction('search', function (mixed $regex) use ($str) {
                if (!($regex instanceof JsRegex)) {
                    return -1;
                }
                $pcre = $regex->toPcre();
                if (preg_match($pcre, $str, $m, PREG_OFFSET_CAPTURE) === 1) {
                    return $m[0][1];
                }
                return -1;
            }),
            default => JsUndefined::Value,
        };
    }

    private function getNumberMethod(int|float $num, string $name): mixed
    {
        return match ($name) {
            'toFixed' => new NativeFunction('toFixed', function (mixed $digits = 0) use ($num) {
                return number_format((float) $num, (int) $digits, '.', '');
            }),
            'toPrecision' => new NativeFunction('toPrecision', function (mixed $digits = null) use ($num) {
                if ($digits === null || $digits === JsUndefined::Value) {
                    return (string) $num;
                }
                $d = (int) $digits;
                return rtrim(rtrim(sprintf("%.{$d}g", (float) $num), '0'), '.');
            }),
            'toExponential' => new NativeFunction('toExponential', function (mixed $digits = null) use ($num) {
                $d = ($digits === null || $digits === JsUndefined::Value) ? 6 : (int) $digits;
                $s = sprintf("%.{$d}e", (float) $num);
                return preg_replace('/e([+-])0*(\d)/', 'e$1$2', $s);
            }),
            'toString' => new NativeFunction('toString', function (mixed $radix = null) use ($num) {
                if ($radix !== null && $radix !== JsUndefined::Value) {
                    return base_convert((string) (int) $num, 10, (int) $radix);
                }
                return (string) $num;
            }),
            default => JsUndefined::Value,
        };
    }

    private function setProperty(): void
    {
        $value = $this->pop();
        $key   = $this->pop();
        $obj   = $this->pop();

        if ($obj instanceof JsArray) {
            $obj->set($key, $value);
        } elseif ($obj instanceof JsObject) {
            $obj->set($key, $value);
        } elseif ($obj instanceof PhpObjectProxy) {
            $obj->set((string) $key, $value);
        }

        $this->push($value);
    }

    // ──────────────────── JS Type Coercion ────────────────────

    private function jsTypeof(mixed $value): string
    {
        if ($value === JsUndefined::Value) {
            return 'undefined';
        }
        if ($value === null) {
            return 'object'; // typeof null === "object" in JS
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if ($value instanceof JsClosure || $value instanceof NativeFunction) {
            return 'function';
        }
        return 'object';
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === JsUndefined::Value) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_float($value) || is_int($value)) {
            return $value !== 0.0 && $value !== 0 && !is_nan((float) $value);
        }
        if (is_string($value)) {
            return $value !== '';
        }
        return true;
    }

    private function toNumber(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if ($value === null) {
            return 0.0;
        }
        if ($value === JsUndefined::Value) {
            return NAN;
        }
        if (is_string($value)) {
            return is_numeric($value) ? (float) $value : NAN;
        }
        return NAN;
    }

    private function toJsString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return 'null';
        }
        if ($value === JsUndefined::Value) {
            return 'undefined';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
            // Format like JS: 1.0 → "1", 1.5 → "1.5"
            return ($value == (int) $value && !is_infinite($value))
                ? (string) (int) $value
                : (string) $value;
        }
        if ($value instanceof JsArray) {
            $parts = [];
            foreach ($value->elements as $el) {
                $parts[] = $this->toJsString($el);
            }
            return implode(',', $parts);
        }
        if ($value instanceof JsObject) {
            return '[object Object]';
        }
        if ($value instanceof JsDate) {
            return $value->toDateString();
        }
        if ($value instanceof JsRegex) {
            return $value->__toString();
        }
        return '[object Object]';
    }

    /**
     * JS Abstract Equality (==) — handles null/undefined and type coercion.
     */
    private function jsLooseEqual(mixed $a, mixed $b): bool
    {
        // null == undefined → true (and vice versa)
        $aIsNullish = ($a === null || $a === JsUndefined::Value);
        $bIsNullish = ($b === null || $b === JsUndefined::Value);
        if ($aIsNullish && $bIsNullish) {
            return true;
        }
        if ($aIsNullish || $bIsNullish) {
            return false; // null/undefined != anything else in JS
        }
        // Boolean → number coercion before comparison
        if (is_bool($a)) { $a = $a ? 1 : 0; }
        if (is_bool($b)) { $b = $b ? 1 : 0; }
        // Delegate to PHP == for remaining cases (string/number coercion works)
        return $a == $b;
    }

    private function strictEqual(mixed $a, mixed $b): bool
    {
        // JS strict equality: type must match exactly
        if (gettype($a) !== gettype($b)) {
            // Special case: both are "number" (int vs float)
            if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
                return (float) $a === (float) $b;
            }
            return false;
        }
        return $a === $b;
    }

    // ──────────────────── Global Environment ────────────────────

    /**
     * Create a global environment with user-provided variables injected.
     *
     * @param array<string, mixed> $vars PHP values to inject as JS globals
     */
    public function createGlobalEnvironmentWithVars(array $vars): Environment
    {
        $env = $this->createGlobalEnvironment();
        foreach ($vars as $name => $value) {
            $env->define($name, self::fromPhp($value));
        }
        return $env;
    }

    private function createGlobalEnvironment(): Environment
    {
        $env = new Environment();
        $vm = $this;

        // ── console ──
        $consoleLog = new NativeFunction('console.log', function () use ($vm) {
            $parts = [];
            foreach (func_get_args() as $arg) {
                $parts[] = $vm->toJsString($arg);
            }
            $vm->output .= implode(' ', $parts) . "\n";
            return JsUndefined::Value;
        });
        $env->define('console', new JsObject([
            'log' => $consoleLog,
        ]));
        $env->define('console_log', $consoleLog); // legacy flat name

        // ── Math ──
        $mathFloor = new NativeFunction('Math.floor', fn(float $n) => floor($n));
        $mathCeil  = new NativeFunction('Math.ceil', fn(float $n) => ceil($n));
        $mathAbs   = new NativeFunction('Math.abs', fn(float $n) => abs($n));
        $mathMax   = new NativeFunction('Math.max', fn(float ...$args) => max($args));
        $mathMin   = new NativeFunction('Math.min', fn(float ...$args) => min($args));
        $mathRound = new NativeFunction('Math.round', fn(float $n) => round($n));
        $mathRandom = new NativeFunction('Math.random', fn() => (float) mt_rand() / (float) mt_getrandmax());
        $env->define('Math', new JsObject([
            'floor'  => $mathFloor,
            'ceil'   => $mathCeil,
            'abs'    => $mathAbs,
            'max'    => $mathMax,
            'min'    => $mathMin,
            'round'  => $mathRound,
            'random' => $mathRandom,
            'sqrt'   => new NativeFunction('Math.sqrt', fn(float $n) => sqrt($n)),
            'pow'    => new NativeFunction('Math.pow', fn(float $b, float $e) => $b ** $e),
            'sin'    => new NativeFunction('Math.sin', fn(float $n) => sin($n)),
            'cos'    => new NativeFunction('Math.cos', fn(float $n) => cos($n)),
            'tan'    => new NativeFunction('Math.tan', fn(float $n) => tan($n)),
            'asin'   => new NativeFunction('Math.asin', fn(float $n) => asin($n)),
            'acos'   => new NativeFunction('Math.acos', fn(float $n) => acos($n)),
            'atan'   => new NativeFunction('Math.atan', fn(float $n) => atan($n)),
            'atan2'  => new NativeFunction('Math.atan2', fn(float $y, float $x) => atan2($y, $x)),
            'log'    => new NativeFunction('Math.log', fn(float $n) => log($n)),
            'log2'   => new NativeFunction('Math.log2', fn(float $n) => log($n, 2)),
            'log10'  => new NativeFunction('Math.log10', fn(float $n) => log10($n)),
            'exp'    => new NativeFunction('Math.exp', fn(float $n) => exp($n)),
            'cbrt'   => new NativeFunction('Math.cbrt', fn(float $n) => $n ** (1/3)),
            'hypot'  => new NativeFunction('Math.hypot', fn(float $a, float $b) => sqrt($a ** 2 + $b ** 2)),
            'sign'   => new NativeFunction('Math.sign', fn(float $n) => $n <=> 0),
            'trunc'  => new NativeFunction('Math.trunc', fn(float $n) => (int) $n),
            'clz32'  => new NativeFunction('Math.clz32', fn(float $n) => $n === 0.0 ? 32 : (31 - (int) floor(log(((int) $n) & 0xFFFFFFFF, 2)))),
            'PI'      => M_PI,
            'E'       => M_E,
            'LN2'     => M_LN2,
            'LN10'    => M_LN10,
            'LOG2E'   => M_LOG2E,
            'LOG10E'  => M_LOG10E,
            'SQRT1_2' => M_SQRT1_2,
            'SQRT2'   => M_SQRT2,
        ]));
        // Legacy flat names
        $env->define('Math_floor', $mathFloor);
        $env->define('Math_ceil', $mathCeil);
        $env->define('Math_abs', $mathAbs);
        $env->define('Math_max', $mathMax);
        $env->define('Math_min', $mathMin);

        // ── Object ──
        $env->define('Object', new JsObject([
            'keys' => new NativeFunction('Object.keys', function (mixed $obj) {
                if ($obj instanceof JsObject) {
                    return new JsArray(array_keys($obj->properties));
                }
                return new JsArray([]);
            }),
            'values' => new NativeFunction('Object.values', function (mixed $obj) {
                if ($obj instanceof JsObject) {
                    return new JsArray(array_values($obj->properties));
                }
                return new JsArray([]);
            }),
            'entries' => new NativeFunction('Object.entries', function (mixed $obj) {
                if ($obj instanceof JsObject) {
                    $entries = [];
                    foreach ($obj->properties as $k => $v) {
                        $entries[] = new JsArray([$k, $v]);
                    }
                    return new JsArray($entries);
                }
                return new JsArray([]);
            }),
            'assign' => new NativeFunction('Object.assign', function (mixed $target) {
                if (!($target instanceof JsObject)) {
                    return $target;
                }
                $sources = array_slice(func_get_args(), 1);
                foreach ($sources as $source) {
                    if ($source instanceof JsObject) {
                        foreach ($source->properties as $k => $v) {
                            $target->set($k, $v);
                        }
                    }
                }
                return $target;
            }),
            'is' => new NativeFunction('Object.is', function (mixed $a, mixed $b) {
                if (is_float($a) && is_float($b)) {
                    if (is_nan($a) && is_nan($b)) {
                        return true;
                    }
                    if ($a === 0.0 && $b === 0.0) {
                        return (1 / $a) === (1 / $b);
                    }
                }
                return $a === $b;
            }),
            'create' => new NativeFunction('Object.create', function (mixed $proto) {
                $obj = new JsObject();
                if ($proto instanceof JsObject) {
                    $obj->prototype = $proto;
                }
                return $obj;
            }),
            'freeze' => new NativeFunction('Object.freeze', function (mixed $obj) {
                return $obj; // no-op — freeze semantics not enforced
            }),
        ]));

        // ── Date ──
        $env->define('Date', new NativeFunction('Date', function () {
            $args = func_get_args();
            if (count($args) === 0) {
                return new JsDate((float) (int) (microtime(true) * 1000));
            }
            if (count($args) === 1) {
                $arg = $args[0];
                if (is_string($arg)) {
                    $ts = strtotime($arg);
                    return new JsDate($ts === false ? NAN : (float) ($ts * 1000));
                }
                return new JsDate((float) $arg);
            }
            // new Date(year, month, day?, hours?, minutes?, seconds?, ms?)
            $y = (int) $args[0];
            $m = (int) $args[1] + 1; // JS months are 0-based
            $d = (int) ($args[2] ?? 1);
            $h = (int) ($args[3] ?? 0);
            $i = (int) ($args[4] ?? 0);
            $s = (int) ($args[5] ?? 0);
            $ms = (int) ($args[6] ?? 0);
            $ts = gmmktime($h, $i, $s, $m, $d, $y);
            return new JsDate((float) ($ts * 1000 + $ms));
        }, [
            'now' => new NativeFunction('Date.now', fn() => (float) (int) (microtime(true) * 1000)),
            'parse' => new NativeFunction('Date.parse', function (string $str) {
                $ts = strtotime($str);
                return $ts === false ? NAN : (float) ($ts * 1000);
            }),
        ]));

        // ── Array ──
        $env->define('Array', new JsObject([
            'isArray' => new NativeFunction('Array.isArray', function (mixed $val) {
                return $val instanceof JsArray;
            }),
            'from' => new NativeFunction('Array.from', function (mixed $val) {
                if ($val instanceof JsArray) {
                    return new JsArray([...$val->elements]);
                }
                return new JsArray([]);
            }),
            'of' => new NativeFunction('Array.of', function () {
                return new JsArray(func_get_args());
            }),
        ]));

        // ── Number ──
        $parseInt = new NativeFunction('parseInt', function (mixed $str, mixed $radix = null) {
            $s = trim((string) $str);
            if ($s === '') {
                return NAN;
            }
            $base = ($radix !== null && $radix !== JsUndefined::Value) ? (int) $radix : 10;
            if ($base === 0) {
                $base = 10;
            }
            if ($base < 2 || $base > 36) {
                return NAN;
            }
            if ($base === 16 && (str_starts_with($s, '0x') || str_starts_with($s, '0X'))) {
                $s = substr($s, 2);
            }
            $valid = ($base <= 10)
                ? '[0-' . ($base - 1) . ']'
                : '[0-9a-' . chr(ord('a') + $base - 11) . 'A-' . chr(ord('A') + $base - 11) . ']';
            if (preg_match('/^[+-]?' . $valid . '+/', $s, $m)) {
                return (float) intval($m[0], $base);
            }
            return NAN;
        });
        $parseFloat = new NativeFunction('parseFloat', function (mixed $str) {
            $s = trim((string) $str);
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $m)) {
                return (float) $m[0];
            }
            return NAN;
        });
        $env->define('Number', new JsObject([
            'isInteger' => new NativeFunction('Number.isInteger', function (mixed $val) {
                return (is_int($val) || is_float($val)) && is_finite((float) $val) && floor((float) $val) === (float) $val;
            }),
            'isFinite' => new NativeFunction('Number.isFinite', function (mixed $val) {
                return (is_int($val) || is_float($val)) && is_finite((float) $val);
            }),
            'isNaN' => new NativeFunction('Number.isNaN', function (mixed $val) {
                return (is_float($val) || is_int($val)) && is_nan((float) $val);
            }),
            'parseInt' => $parseInt,
            'parseFloat' => $parseFloat,
            'MAX_SAFE_INTEGER' => 9007199254740991.0,
            'MIN_SAFE_INTEGER' => -9007199254740991.0,
            'EPSILON' => PHP_FLOAT_EPSILON,
            'POSITIVE_INFINITY' => INF,
            'NEGATIVE_INFINITY' => -INF,
            'NaN' => NAN,
        ]));

        // ── String ──
        $env->define('String', new JsObject([
            'fromCharCode' => new NativeFunction('String.fromCharCode', function () {
                $result = '';
                foreach (func_get_args() as $code) {
                    $result .= mb_chr((int) $code);
                }
                return $result;
            }),
        ]));

        // ── JSON ──
        $env->define('JSON', new JsObject([
            'stringify' => new NativeFunction('JSON.stringify', function (mixed $value, mixed $replacer = null, mixed $space = null) use ($vm) {
                $php = self::toJsonSafe($value);
                $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
                if ($space !== null && $space !== JsUndefined::Value) {
                    $flags |= JSON_PRETTY_PRINT;
                }
                return json_encode($php, $flags);
            }),
            'parse' => new NativeFunction('JSON.parse', function (string $json) {
                $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                return self::fromPhp($result);
            }),
        ]));

        // ── RegExp ──
        $env->define('RegExp', new NativeFunction('RegExp', function (mixed $pattern, mixed $flags = '') {
            return new JsRegex((string) $pattern, (string) ($flags === JsUndefined::Value ? '' : $flags));
        }));

        // ── Global functions ──
        $env->define('parseInt', $parseInt);
        $env->define('parseFloat', $parseFloat);
        $env->define('isNaN', new NativeFunction('isNaN', function (mixed $val) use ($vm) {
            $n = $vm->toNumber($val);
            return is_nan($n);
        }));
        $env->define('isFinite', new NativeFunction('isFinite', function (mixed $val) use ($vm) {
            $n = $vm->toNumber($val);
            return is_finite($n);
        }));

        // ── Global constants ──
        $env->define('NaN', NAN);
        $env->define('Infinity', INF);
        $env->define('undefined', JsUndefined::Value);

        // ── URI encoding/decoding ──
        $env->define('encodeURIComponent', new NativeFunction('encodeURIComponent', fn(mixed $str) => rawurlencode((string) $str)));
        $env->define('decodeURIComponent', new NativeFunction('decodeURIComponent', fn(mixed $str) => rawurldecode((string) $str)));
        $env->define('encodeURI', new NativeFunction('encodeURI', function (mixed $str) {
            $encoded = rawurlencode((string) $str);
            return str_replace(
                ['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'],
                [':', '/', '?', '#', '[', ']', '@', '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '='],
                $encoded
            );
        }));
        $env->define('decodeURI', new NativeFunction('decodeURI', fn(mixed $str) => rawurldecode((string) $str)));

        return $env;
    }

    /**
     * Convert a PHP-native value to a JS runtime value.
     *
     * - Indexed arrays → JsArray
     * - Associative arrays → JsObject
     * - Closures → NativeFunction
     * - Scalars/null pass through
     */
    public static function fromPhp(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        if ($value instanceof \Closure) {
            return new NativeFunction('(php)', $value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return new JsArray(array_map(self::fromPhp(...), $value));
            }
            $props = [];
            foreach ($value as $k => $v) {
                $props[(string) $k] = self::fromPhp($v);
            }
            return new JsObject($props);
        }
        if (is_object($value)) {
            return new PhpObjectProxy($value);
        }
        return $value;
    }

    /**
     * Convert a JS value to a json_encode-safe PHP value (objects → stdClass).
     */
    private static function toJsonSafe(mixed $value): mixed
    {
        if ($value === JsUndefined::Value) {
            return null;
        }
        if ($value instanceof JsArray) {
            return array_map(self::toJsonSafe(...), $value->elements);
        }
        if ($value instanceof JsObject) {
            $obj = new \stdClass();
            foreach ($value->properties as $k => $v) {
                $obj->$k = self::toJsonSafe($v);
            }
            return $obj;
        }
        return $value;
    }

    /**
     * Convert a JS value to a PHP-native value for external consumption.
     */
    public static function toPhp(mixed $value): mixed
    {
        if ($value === JsUndefined::Value) {
            return null;
        }
        if ($value instanceof JsArray) {
            return array_map(self::toPhp(...), $value->elements);
        }
        if ($value instanceof JsObject) {
            $result = [];
            foreach ($value->properties as $k => $v) {
                $result[$k] = self::toPhp($v);
            }
            return $result;
        }
        if ($value instanceof JsDate) {
            return $value->getTimestamp();
        }
        if ($value instanceof JsRegex) {
            return $value->__toString();
        }
        if ($value instanceof PhpObjectProxy) {
            return $value->target;
        }
        if (is_float($value) && $value == (int) $value && !is_infinite($value)) {
            return (int) $value;
        }
        return $value;
    }
}
