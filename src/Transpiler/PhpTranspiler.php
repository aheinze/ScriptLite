<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler;

use ScriptLite\Ast\{
    ArrayLiteral, AssignExpr, BinaryExpr, BlockStmt, BooleanLiteral, BreakStmt, CallExpr,
    ConditionalExpr, ContinueStmt, DestructuringDeclaration, DeleteExpr, DoWhileStmt,
    ExpressionStmt, Expr, ForInStmt, ForOfStmt, ForStmt,
    FunctionDeclaration, FunctionExpr,
    Identifier, IfStmt, LogicalExpr, MemberAssignExpr, MemberExpr, NewExpr, NullLiteral,
    NumberLiteral, ObjectLiteral, Program, RegexLiteral, ReturnStmt, SequenceExpr, SpreadElement, Stmt, StringLiteral,
    SwitchStmt, TemplateLiteral, ThisExpr, ThrowStmt, TryCatchStmt, TypeofExpr, UnaryExpr,
    UndefinedLiteral, UpdateExpr, VarDeclarationList, VoidExpr, VarDeclaration, VarKind, WhileStmt
};
use RuntimeException;

/**
 * Transpiles JS AST directly to PHP source code.
 *
 * Instead of generating bytecode for our stack-based VM, this emits PHP code
 * that OPcache/JIT can compile to native machine code — eliminating the entire
 * VM dispatch overhead. JS arrays → PHP arrays, JS objects → PHP assoc arrays,
 * JS functions → PHP closures, JS control flow → PHP control flow.
 */
final class PhpTranspiler
{
    private const OPS = '\\ScriptLite\\Transpiler\\Runtime\\Ops';
    private const JS_FUNCTION = '\\ScriptLite\\Transpiler\\Runtime\\JSFunction';
    private const JS_OBJECT = '\\ScriptLite\\Transpiler\\Runtime\\JSObject';

    private int $tmpId = 0;

    /** @var list<array<string, true>> scope stack: each frame is set of variable names */
    private array $scopes = [];

    private bool $inConstructor = false;

    /** @var array<string, TypeHint> tracked variable types for optimization */
    private array $varTypes = [];

    /** Current lexical block nesting depth for let/const shadowing decisions. */
    private int $blockDepth = 0;

    /** @var array<string, true> function bindings that need JSFunction boxing */
    private array $boxedFunctionBindings = [];

    /** @var array<string, array<string, true>> aliases: target name -> possible source names */
    private array $functionBindingAliases = [];

    /**
     * Transpile a parsed JS program to executable PHP source.
     * The returned string can be eval()'d or written to a file and include()'d.
     */
    /**
     * @param string[] $globalNames Variable names injected from PHP (for scope tracking)
     */
    public function transpile(Program $program, array $globalNames = []): string
    {
        $this->scopes = [];
        $this->tmpId = 0;
        $this->varTypes = [];
        $this->letRenames = [];
        $this->blockDepth = 0;
        $this->boxedFunctionBindings = [];
        $this->functionBindingAliases = [];
        $this->collectBoxedFunctionBindings($program->body);

        $this->pushScope([]);
        foreach ($globalNames as $name) {
            $this->addVar($name);
        }
        $hoisted = $this->collectVarDecls($program->body);
        foreach ($hoisted as $v) {
            $this->addVar($v);
        }

        $body = "\$__out = '';\n";

        // Hoist function declarations
        foreach ($program->body as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $body .= $this->emitStmt($stmt);
            }
        }

        $stmts = array_values(array_filter(
            $program->body,
            fn($s) => !($s instanceof FunctionDeclaration)
        ));
        $lastIdx = count($stmts) - 1;

        foreach ($stmts as $i => $stmt) {
            if ($i === $lastIdx && $stmt instanceof ExpressionStmt) {
                $body .= '$__result = ' . $this->emitExpr($stmt->expression) . ";\n";
            } else {
                $body .= $this->emitStmt($stmt);
            }
        }

        $body .= "return \$__result ?? null;\n";

        $this->popScope();

        return "return (static function(array \$__g = []) {\n    extract(\$__g);\n" . $this->indent($body) . "})(\$__globals ?? []);\n";
    }

    /**
     * Emit an expression in boolean context using JS truthiness semantics.
     * Skips the wrapper when the type is already known to be boolean.
     */
    private function emitCondition(Expr $e): string
    {
        $type = $this->inferType($e);
        if ($type === TypeHint::Bool) {
            return $this->emitExpr($e);
        }
        if ($type === TypeHint::Numeric) {
            $emitted = $this->emitExpr($e);
            // Numeric: 0, 0.0, NaN are falsy. Inline avoids function call.
            [$a, $r] = $this->inlineGuardVar($emitted, 'c');
            return "({$a} !== 0 && {$r} !== 0.0 && (!is_float({$r}) || !is_nan({$r})))";
        }
        if ($type === TypeHint::String) {
            return '(' . $this->emitExpr($e) . " !== '')";
        }
        if ($type === TypeHint::Array_ || $type === TypeHint::Object_) {
            // Arrays and objects are always truthy in JS
            // Still evaluate the expression for side effects, then return true
            return '((' . $this->emitExpr($e) . ') || true)';
        }
        return self::OPS . '::toBoolean(' . $this->emitExpr($e) . ')';
    }

    // ───────────────── Statements ─────────────────

    private function emitStmt(Stmt $stmt): string
    {
        return match (true) {
            $stmt instanceof ExpressionStmt => ($stmt->expression instanceof UpdateExpr
                ? $this->emitUpdateVoid($stmt->expression) : $this->emitExpr($stmt->expression)) . ";\n",
            $stmt instanceof VarDeclaration => $this->emitVarDecl($stmt),
            $stmt instanceof FunctionDeclaration => $this->emitFuncDecl($stmt),
            $stmt instanceof ReturnStmt => $this->emitReturn($stmt),
            $stmt instanceof IfStmt => $this->emitIf($stmt),
            $stmt instanceof WhileStmt => $this->emitWhile($stmt),
            $stmt instanceof ForStmt => $this->emitFor($stmt),
            $stmt instanceof ForOfStmt => $this->emitForOf($stmt),
            $stmt instanceof ForInStmt => $this->emitForIn($stmt),
            $stmt instanceof DestructuringDeclaration => $this->emitDestructuring($stmt),
            $stmt instanceof VarDeclarationList => $this->emitVarDeclList($stmt),
            $stmt instanceof DoWhileStmt => $this->emitDoWhile($stmt),
            $stmt instanceof BreakStmt => "break;\n",
            $stmt instanceof ContinueStmt => "continue;\n",
            $stmt instanceof SwitchStmt => $this->emitSwitch($stmt),
            $stmt instanceof ThrowStmt => $this->emitThrow($stmt),
            $stmt instanceof TryCatchStmt => $this->emitTryCatch($stmt),
            $stmt instanceof BlockStmt => $this->emitBlock($stmt),
            default => throw new RuntimeException('Transpiler: unsupported stmt ' . get_class($stmt)),
        };
    }

    /** @var array<string, string> let/const rename map: original name → current PHP name */
    private array $letRenames = [];

    private function emitVarDecl(VarDeclaration $d): string
    {
        $phpName = $d->name;

        // let/const block-scoping: rename bindings introduced in nested lexical
        // blocks, or when they shadow an already-active lexical binding.
        if ($d->kind !== VarKind::Var) {
            if ($this->blockDepth > 0 || array_key_exists($d->name, $this->letRenames)) {
                $phpName = $d->name . '__' . $this->tmpId++;
            }
            $this->letRenames[$d->name] = $phpName;
        }

        $this->addVar($phpName);
        if ($d->initializer !== null) {
            $initializer = $d->initializer;
            $this->trackVar($phpName, $this->inferType($initializer));
            $val = $initializer instanceof FunctionExpr
                ? $this->emitFunctionExpr($initializer, $d->name)
                : $this->emitExpr($initializer);
        } else {
            $val = 'null';
        }
        return '$' . $phpName . ' = ' . $val . ";\n";
    }

    private function emitVarDeclList(VarDeclarationList $list): string
    {
        $out = '';
        foreach ($list->declarations as $d) {
            $out .= $this->emitVarDecl($d);
        }
        return $out;
    }

    private function emitFuncDecl(FunctionDeclaration $d): string
    {
        $this->addVar($d->name);
        return '$' . $d->name . ' = ' . $this->emitClosure(
            $d->name,
            $d->params,
            $d->body,
            restParam: $d->restParam,
            boxFunction: $this->needsBoxedFunction($d->name, false, $d->body),
            defaults: $d->defaults,
            paramDestructures: $d->paramDestructures,
        ) . ";\n";
    }

    private function emitReturn(ReturnStmt $s): string
    {
        if ($this->inConstructor) {
            $val = $s->value !== null ? $this->emitExpr($s->value) : 'null';
            // Constructor: if return value is not an object, return the constructed instance.
            return '{ $__rv = ' . $val . '; return ' . self::OPS . '::isObjectLike($__rv) ? $__rv : $__this; }' . "\n";
        }
        return 'return ' . ($s->value !== null ? $this->emitExpr($s->value) : 'null') . ";\n";
    }

    private function emitIf(IfStmt $s): string
    {
        $saved = $this->varTypes;
        $out = 'if (' . $this->emitCondition($s->condition) . ") {\n";
        $out .= $this->indent($this->emitStmt($s->consequent));
        $out .= '}';
        $afterThen = $this->varTypes;

        if ($s->alternate !== null) {
            $this->varTypes = $saved;
            $out .= " else {\n";
            $out .= $this->indent($this->emitStmt($s->alternate));
            $out .= '}';
            $afterElse = $this->varTypes;
            // Keep only types that both branches agree on
            $this->varTypes = $this->mergeBranchTypes($afterThen, $afterElse);
        } else {
            // Body may or may not execute — merge with pre-branch state
            $this->varTypes = $this->mergeBranchTypes($saved, $afterThen);
        }
        return $out . "\n";
    }

    private function emitWhile(WhileStmt $s): string
    {
        $saved = $this->varTypes;
        $out = "while (" . $this->emitCondition($s->condition) . ") {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
        $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
        return $out;
    }

    private function emitFor(ForStmt $s): string
    {
        $init = '';
        if ($s->init instanceof VarDeclaration) {
            $this->addVar($s->init->name);
            if ($s->init->initializer !== null) {
                $this->trackVar($s->init->name, $this->inferType($s->init->initializer));
            }
            $val = $s->init->initializer !== null ? $this->emitExpr($s->init->initializer) : 'null';
            $init = '$' . $s->init->name . ' = ' . $val;
        } elseif ($s->init instanceof VarDeclarationList) {
            $parts = [];
            foreach ($s->init->declarations as $d) {
                $this->addVar($d->name);
                if ($d->initializer !== null) {
                    $this->trackVar($d->name, $this->inferType($d->initializer));
                }
                $val = $d->initializer !== null ? $this->emitExpr($d->initializer) : 'null';
                $parts[] = '$' . $d->name . ' = ' . $val;
            }
            $init = implode(', ', $parts);
        } elseif ($s->init instanceof DestructuringDeclaration) {
            // Destructuring in for-init: emit as statement before the loop
            $saved = $this->varTypes;
            $pre = $this->emitDestructuring($s->init);
            $cond = $s->condition !== null ? $this->emitExpr($s->condition) : '';
            $upd = $s->update !== null
                ? ($s->update instanceof UpdateExpr ? $this->emitUpdateVoid($s->update) : $this->emitExpr($s->update))
                : '';
            $out = $pre . "for (; {$cond}; {$upd}) {\n"
                . $this->indent($this->emitStmt($s->body))
                . "}\n";
            $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
            return $out;
        } elseif ($s->init instanceof ExpressionStmt) {
            $init = $this->emitExpr($s->init->expression);
        }

        $cond = $s->condition !== null ? $this->emitExpr($s->condition) : '';
        $upd = $this->emitForUpdate($s->update);

        $saved = $this->varTypes;
        $out = "for ({$init}; {$cond}; {$upd}) {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
        $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
        return $out;
    }

    private function emitForUpdate(?Expr $update): string
    {
        if ($update === null) {
            return '';
        }
        // SequenceExpr in for-update: emit as comma-separated expressions
        if ($update instanceof SequenceExpr) {
            $parts = [];
            foreach ($update->expressions as $expr) {
                $parts[] = $expr instanceof UpdateExpr ? $this->emitUpdateVoid($expr) : $this->emitExpr($expr);
            }
            return implode(', ', $parts);
        }
        return $update instanceof UpdateExpr ? $this->emitUpdateVoid($update) : $this->emitExpr($update);
    }

    private function emitForOf(ForOfStmt $s): string
    {
        $this->addVar($s->name);
        $saved = $this->varTypes;
        $iter = $this->emitExpr($s->iterable);
        $out = "foreach ({$iter} as \${$s->name}) {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
        $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
        return $out;
    }

    private function emitForIn(ForInStmt $s): string
    {
        $this->addVar($s->name);
        $saved = $this->varTypes;
        $obj = $this->emitExpr($s->object);
        $this->trackVar($s->name, TypeHint::String);
        $out = 'foreach (' . self::OPS . "::forInKeys({$obj}) as \${$s->name}) {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
        $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
        return $out;
    }

    private function emitDestructuring(DestructuringDeclaration $d): string
    {
        $tmp = '$__d' . ($this->tmpId++);
        $out = "{$tmp} = " . $this->emitExpr($d->initializer) . ";\n";
        $out .= $this->emitDestructuringPattern($tmp, $d->isArray, $d->bindings, $d->restName);
        return $out;
    }

    private function emitDestructuringPattern(string $src, bool $isArray, array $bindings, ?string $restName): string
    {
        $out = '';
        foreach ($bindings as $b) {
            if ($isArray) {
                $key = $b['source'];
                $access = "{$src}[{$key}]";
            } else {
                $key = var_export($b['source'], true);
                $access = self::OPS . "::getNamedProp({$src}, {$key})";
            }

            // Nested pattern: store in temp and recurse
            if ($b['name'] === null && isset($b['nested'])) {
                $nestedTmp = '$__d' . ($this->tmpId++);
                if ($b['default'] !== null) {
                    $def = $this->emitExpr($b['default']);
                    $out .= "{$nestedTmp} = " . self::OPS . "::hasOwn({$src}, {$key}) ? {$access} : {$def};\n";
                } else {
                    $out .= "{$nestedTmp} = {$access};\n";
                }
                $n = $b['nested'];
                $out .= $this->emitDestructuringPattern($nestedTmp, $n['isArray'], $n['bindings'], $n['restName']);
                continue;
            }

            $this->addVar($b['name']);
            if ($b['default'] !== null) {
                $def = $this->emitExpr($b['default']);
                $out .= '$' . $b['name'] . ' = ' . self::OPS . "::hasOwn({$src}, {$key}) ? {$access} : {$def};\n";
            } else {
                $out .= '$' . $b['name'] . " = {$access} ?? null;\n";
            }
        }

        if ($restName !== null && $isArray) {
            $this->addVar($restName);
            $out .= '$' . $restName . " = array_slice({$src}, " . count($bindings) . ");\n";
        }

        return $out;
    }

    private function emitDoWhile(DoWhileStmt $s): string
    {
        $saved = $this->varTypes;
        $out = "do {\n"
            . $this->indent($this->emitStmt($s->body))
            . "} while (" . $this->emitCondition($s->condition) . ");\n";
        // After first iteration types may change on subsequent iterations
        $this->varTypes = $this->mergeBranchTypes($saved, $this->varTypes);
        return $out;
    }

    private function emitSwitch(SwitchStmt $s): string
    {
        // Compare via Ops::strictEquals() so JS Number semantics and object identity are preserved.
        $disc = '$__sw' . ($this->tmpId++);
        $out = "{$disc} = " . $this->emitExpr($s->discriminant) . ";\n";
        $out .= "switch (true) {\n";
        $saved = $this->varTypes;
        $merged = $saved;
        foreach ($s->cases as $case) {
            $this->varTypes = $saved;
            if ($case->test !== null) {
                $out .= 'case (' . self::OPS . "::strictEquals({$disc}, " . $this->emitExpr($case->test) . ")):\n";
            } else {
                $out .= "default:\n";
            }
            foreach ($case->consequent as $stmt) {
                $out .= $this->indent($this->emitStmt($stmt));
            }
            $merged = $this->mergeBranchTypes($merged, $this->varTypes);
        }
        $this->varTypes = $merged;
        $out .= "}\n";
        return $out;
    }

    private function emitThrow(ThrowStmt $s): string
    {
        return "throw new \\ScriptLite\\Vm\\JsThrowable(" . $this->emitExpr($s->argument) . ");\n";
    }

    private function emitTryCatch(TryCatchStmt $s): string
    {
        $saved = $this->varTypes;
        $out = "try {\n" . $this->indent($this->emitBlock($s->block)) . "}";
        $afterTry = $this->varTypes;
        if ($s->handler !== null) {
            $this->varTypes = $saved;
            $param = $s->handler->param;
            if ($param !== null) {
                // JS catch param is block-scoped: save outer value, restore after catch
                $shadow = in_array($param, $this->getAllScopeVars(), true);
                $saveVar = '$__save_' . $param . '_' . $this->tmpId++;
                $this->addVar($param);
                $out .= " catch (\\Throwable \$__ex) {\n";
                $body = '';
                if ($shadow) {
                    $body .= "{$saveVar} = \${$param} ?? null;\n";
                }
                $body .= '$' . $param . " = \$__ex instanceof \\ScriptLite\\Vm\\JsThrowable ? \$__ex->value : \$__ex->getMessage();\n"
                    . $this->emitBlock($s->handler->body);
                if ($shadow) {
                    $body .= "\${$param} = {$saveVar};\n";
                }
                $out .= $this->indent($body);
                $out .= "}";
            } else {
                // Optional catch binding: catch { } — no parameter needed
                $out .= " catch (\\Throwable) {\n";
                $out .= $this->indent($this->emitBlock($s->handler->body));
                $out .= "}";
            }
            $this->varTypes = $this->mergeBranchTypes($afterTry, $this->varTypes);
        } else {
            $this->varTypes = $this->mergeBranchTypes($saved, $afterTry);
        }
        if ($s->finalizer !== null) {
            $out .= " finally {\n" . $this->indent($this->emitBlock($s->finalizer)) . "}";
        }
        $out .= "\n";
        return $out;
    }

    private function emitBlock(BlockStmt $b): string
    {
        $savedRenames = $this->letRenames;
        $this->blockDepth++;
        $out = '';
        foreach ($b->statements as $s) {
            $out .= $this->emitStmt($s);
        }
        $this->blockDepth--;
        $this->letRenames = $savedRenames;
        return $out;
    }

    // ───────────────── Expressions ─────────────────

    private function emitExpr(Expr $e): string
    {
        return match (true) {
            $e instanceof NumberLiteral => $this->emitNumber($e->value),
            $e instanceof StringLiteral => $this->escapeStr($e->value),
            $e instanceof BooleanLiteral => $e->value ? 'true' : 'false',
            $e instanceof NullLiteral => 'null',
            $e instanceof UndefinedLiteral => 'null',
            $e instanceof Identifier => match ($e->name) {
                'NaN' => 'NAN',
                'Infinity' => 'INF',
                'undefined' => 'null',
                default => '$' . ($this->letRenames[$e->name] ?? $e->name),
            },
            $e instanceof BinaryExpr => $this->emitBinary($e),
            $e instanceof UnaryExpr => $this->emitUnary($e),
            $e instanceof AssignExpr => $this->emitAssign($e),
            $e instanceof CallExpr => $this->emitCall($e),
            $e instanceof FunctionExpr => $this->emitFunctionExpr($e),
            $e instanceof LogicalExpr => $this->emitLogical($e),
            $e instanceof ConditionalExpr => '(' . $this->emitCondition($e->condition) . ' ? ' . $this->emitExpr($e->consequent) . ' : ' . $this->emitExpr($e->alternate) . ')',
            $e instanceof TypeofExpr => $this->emitTypeof($e),
            $e instanceof ArrayLiteral => $this->emitArray($e),
            $e instanceof ObjectLiteral => $this->emitObject($e),
            $e instanceof MemberExpr => $this->emitMember($e),
            $e instanceof MemberAssignExpr => $this->emitMemberAssign($e),
            $e instanceof ThisExpr => '$__this',
            $e instanceof NewExpr => $this->emitNew($e),
            $e instanceof RegexLiteral => $this->emitRegex($e),
            $e instanceof TemplateLiteral => $this->emitTemplateLiteral($e),
            $e instanceof UpdateExpr => $this->emitUpdate($e),
            $e instanceof VoidExpr => '((' . $this->emitExpr($e->operand) . ') ? null : null)',
            $e instanceof DeleteExpr => $this->emitDelete($e),
            $e instanceof SequenceExpr => $this->emitSequence($e),
            default => throw new RuntimeException('Transpiler: unsupported expr ' . get_class($e)),
        };
    }

    private function emitNumber(int|float $v): string
    {
        if (is_nan($v)) return 'NAN';
        if (is_infinite($v)) return $v > 0 ? 'INF' : '-INF';
        return $v == (int) $v && !is_infinite((float) $v) ? (string)(int)$v : (string)$v;
    }

    private function emitBinary(BinaryExpr $e): string
    {
        $l = $this->emitExpr($e->left);
        $r = $this->emitExpr($e->right);

        if ($e->operator === '+') {
            // JS + : string concat if either operand is string, else numeric
            $leftType  = $this->inferType($e->left);
            $rightType = $this->inferType($e->right);

            // Fast path: both provably numeric → native PHP +
            if ($leftType === TypeHint::Numeric && $rightType === TypeHint::Numeric) {
                return '(' . $l . ' + ' . $r . ')';
            }

            // Fast path: either provably string → string concat
            if ($leftType === TypeHint::String || $rightType === TypeHint::String) {
                // '' + expr → just toString(expr), skip redundant '' . concatenation
                if ($e->left instanceof StringLiteral && $e->left->value === '' && $rightType !== TypeHint::String) {
                    return self::OPS . '::toString(' . $r . ')';
                }
                if ($e->right instanceof StringLiteral && $e->right->value === '' && $leftType !== TypeHint::String) {
                    return self::OPS . '::toString(' . $l . ')';
                }
                $ls = $leftType === TypeHint::String ? $l : self::OPS . '::toString(' . $l . ')';
                $rs = $rightType === TypeHint::String ? $r : self::OPS . '::toString(' . $r . ')';
                return '(' . $ls . ' . ' . $rs . ')';
            }

            // Inline numeric fast path: when one side is already known-numeric, only guard the other
            $leftNum = $leftType === TypeHint::Numeric;
            $rightNum = $rightType === TypeHint::Numeric;
            if ($leftNum) {
                // Left is numeric: only need to check right
                [$ra, $rr] = $this->inlineGuardVar($r, 'b');
                return "((is_int({$ra}) || is_float({$rr})) ? {$l} + {$rr} : " . self::OPS . "::add({$l}, {$rr}))";
            }
            if ($rightNum) {
                // Right is numeric: only need to check left
                [$la, $lr] = $this->inlineGuardVar($l, 'a');
                return "((is_int({$la}) || is_float({$lr})) ? {$lr} + {$r} : " . self::OPS . "::add({$lr}, {$r}))";
            }
            // Both unknown: use & (not &&) so both sides always execute
            [$la, $lr] = $this->inlineGuardVar($l, 'a');
            [$ra, $rr] = $this->inlineGuardVar($r, 'b');
            return "((is_int({$la}) || is_float({$lr})) & (is_int({$ra}) || is_float({$rr})) ? {$lr} + {$rr} : " . self::OPS . "::add({$lr}, {$rr}))";
        }

        if ($e->operator === '>>>') {
            return '(((int)(' . $l . ') & 0xFFFFFFFF) >> ((int)(' . $r . ') & 0x1F))';
        }

        if ($e->operator === 'in') {
            return self::OPS . '::hasProperty(' . $r . ', ' . $l . ')';
        }

        if ($e->operator === 'instanceof') {
            $right = $e->right instanceof FunctionExpr
                ? $this->emitFunctionExpr($e->right, forceBox: true)
                : $r;
            return self::OPS . '::instanceOf(' . $l . ', ' . $right . ')';
        }

        if ($e->operator === '==') {
            $fast = $this->emitFastEquality($e->left, $e->right, strict: false);
            if ($fast !== null) {
                return $fast;
            }
            return self::OPS . '::looseEquals(' . $l . ', ' . $r . ')';
        }
        if ($e->operator === '!=') {
            $fast = $this->emitFastEquality($e->left, $e->right, strict: false);
            if ($fast !== null) {
                return '(!' . $fast . ')';
            }
            return '(!' . self::OPS . '::looseEquals(' . $l . ', ' . $r . '))';
        }
        if ($e->operator === '===') {
            $fast = $this->emitFastEquality($e->left, $e->right, strict: true);
            if ($fast !== null) {
                return $fast;
            }
            return self::OPS . '::strictEquals(' . $l . ', ' . $r . ')';
        }
        if ($e->operator === '!==') {
            $fast = $this->emitFastEquality($e->left, $e->right, strict: true);
            if ($fast !== null) {
                return '(!' . $fast . ')';
            }
            return '(!' . self::OPS . '::strictEquals(' . $l . ', ' . $r . '))';
        }

        // JS / and %: use Ops methods to handle division by zero → NaN/Infinity
        if ($e->operator === '/') {
            if (
                $this->inferType($e->left) === TypeHint::Numeric
                && $this->inferType($e->right) === TypeHint::Numeric
                && $e->right instanceof NumberLiteral
                && $e->right->value != 0
            ) {
                return '(' . $l . ' / ' . $r . ')';
            }
            return self::OPS . '::div(' . $l . ', ' . $r . ')';
        }
        if ($e->operator === '%') {
            // Known numerics with non-zero literal divisor: use native %
            if (
                $this->inferType($e->left) === TypeHint::Numeric
                && $this->inferType($e->right) === TypeHint::Numeric
                && $e->right instanceof NumberLiteral
                && $e->right->value != 0
            ) {
                return '(' . $l . ' % ' . $r . ')';
            }
            return self::OPS . '::mod(' . $l . ', ' . $r . ')';
        }

        $op = match ($e->operator) {
            '-', '*', '**' => $e->operator,
            '&', '|', '^', '<<', '>>' => $e->operator,
            '<', '<=', '>', '>=' => $e->operator,
            default => throw new RuntimeException("Transpiler: unknown binary op {$e->operator}"),
        };

        // JS arithmetic ops: coerce operands to number (strings, bools, null → number)
        if (in_array($e->operator, ['-', '*', '%', '**'], true)) {
            $leftType = $this->inferType($e->left);
            $rightType = $this->inferType($e->right);
            if ($leftType !== TypeHint::Numeric) {
                [$na, $nr] = $this->inlineGuardVar($l, 'n');
                $l = "(is_int({$na}) || is_float({$nr}) ? {$nr} : " . self::OPS . "::toNumber({$nr}))";
            }
            if ($rightType !== TypeHint::Numeric) {
                [$na, $nr] = $this->inlineGuardVar($r, 'n');
                $r = "(is_int({$na}) || is_float({$nr}) ? {$nr} : " . self::OPS . "::toNumber({$nr}))";
            }
        }

        // JS relational ops: convert booleans to numbers (PHP quirk: true < 3 === false)
        if (in_array($e->operator, ['<', '<=', '>', '>='], true)) {
            $leftType = $this->inferType($e->left);
            $rightType = $this->inferType($e->right);
            if ($leftType === TypeHint::Bool) { $l = '(int)(' . $l . ')'; }
            if ($rightType === TypeHint::Bool) { $r = '(int)(' . $r . ')'; }
        }

        return '(' . $l . ' ' . $op . ' ' . $r . ')';
    }

    private function emitUnary(UnaryExpr $e): string
    {
        if ($e->operator === '-') {
            $operand = $this->emitExpr($e->operand);
            if ($this->inferType($e->operand) === TypeHint::Numeric) {
                return '(-' . $operand . ')';
            }
            [$na, $nr] = $this->inlineGuardVar($operand, 'n');
            return "(-(is_int({$na}) || is_float({$nr}) ? {$nr} : " . self::OPS . "::toNumber({$nr})))";
        }

        return match ($e->operator) {
            '!' => '(!' . $this->emitCondition($e->operand) . ')',
            '~' => '(~(int)' . $this->emitExpr($e->operand) . ')',
            default => throw new RuntimeException("Transpiler: unknown unary op {$e->operator}"),
        };
    }

    /**
     * Emit an update expression (++/--) in void context — result is discarded.
     * Avoids expensive IIFE closure by emitting a simple assignment or native ++/--.
     */
    private function emitUpdateVoid(UpdateExpr $e): string
    {
        $sign = $e->operator === '++' ? '+' : '-';
        $increment = $e->operator === '++' ? 'true' : 'false';

        if ($e->argument instanceof Identifier) {
            $var = '$' . $this->resolveVar($e->argument->name);
            $type = $this->inferFromName($e->argument->name);

            if ($type === TypeHint::Numeric) {
                return $e->operator === '++' ? "{$var}++" : "{$var}--";
            }

            return "{$var} = " . self::OPS . "::toNumber({$var}) {$sign} 1";
        }

        if ($e->argument instanceof MemberExpr) {
            if ($e->argument->object instanceof Identifier || $e->argument->object instanceof ThisExpr) {
                $obj = $e->argument->object instanceof ThisExpr
                    ? '$__this'
                    : '$' . $this->resolveVar($e->argument->object->name);

                if (!$e->argument->computed && $e->argument->property instanceof Identifier) {
                    $key = "'" . $e->argument->property->name . "'";
                    return "{$obj}[{$key}] = " . self::OPS . "::toNumber({$obj}[{$key}]) {$sign} 1";
                }

                $key = $this->emitExpr($e->argument->property);
                return self::OPS . "::updateProp({$obj}, {$key}, {$increment}, true)";
            }

            return $this->emitCachedMemberUpdate($e, true);
        }

        // Fallback: use the full IIFE version
        return $this->emitUpdate($e);
    }

    private function emitUpdate(UpdateExpr $e): string
    {
        $increment = $e->operator === '++' ? 'true' : 'false';

        if ($e->argument instanceof Identifier) {
            $var = '$' . $this->resolveVar($e->argument->name);
            if ($this->inferFromName($e->argument->name) === TypeHint::Numeric) {
                $op = $e->operator;
                return $e->prefix ? "({$op}{$var})" : "({$var}{$op})";
            }
            $old = '$__u' . $this->tmpId++;
            $sign = $e->operator === '++' ? '+' : '-';

            return '(function() use (&' . $var . ') { '
                . $old . ' = ' . $var . '; '
                . $var . ' = ' . self::OPS . '::toNumber(' . $var . ') ' . $sign . ' 1; '
                . 'return ' . ($e->prefix ? $var : $old) . '; })()';
        }
        if ($e->argument instanceof MemberExpr) {
            if ($e->argument->object instanceof Identifier || $e->argument->object instanceof ThisExpr) {
                $obj = $e->argument->object instanceof ThisExpr
                    ? '$__this'
                    : '$' . $this->resolveVar($e->argument->object->name);

                if (!$e->argument->computed && $e->argument->property instanceof Identifier) {
                    $key = "'" . $e->argument->property->name . "'";
                    if ($e->prefix) {
                        $sign = $e->operator === '++' ? '+' : '-';
                        return "({$obj}[{$key}] = " . self::OPS . "::toNumber({$obj}[{$key}]) {$sign} 1)";
                    }

                    return self::OPS . "::updateNamedProp({$obj}, {$key}, {$increment}, false)";
                }

                $key = $this->emitExpr($e->argument->property);
                return self::OPS . "::updateProp({$obj}, {$key}, {$increment}, " . ($e->prefix ? 'true' : 'false') . ")";
            }

            return $this->emitCachedMemberUpdate($e, false);
        }
        throw new RuntimeException('Invalid update target');
    }

    private function emitDelete(DeleteExpr $e): string
    {
        if ($e->operand instanceof MemberExpr) {
            $obj = $this->emitExpr($e->operand->object);
            $key = $e->operand->computed
                ? $this->emitExpr($e->operand->property)
                : "'" . $e->operand->property->name . "'";
            return "(function() use (&{$obj}) { unset({$obj}[{$key}]); return true; })()";
        }
        return 'true';
    }

    private function emitSequence(SequenceExpr $e): string
    {
        // In PHP, comma expressions are emitted as: (expr1, expr2, ..., exprN)
        // We use an IIFE to evaluate all and return the last value
        $parts = [];
        foreach ($e->expressions as $expr) {
            $parts[] = $this->emitExpr($expr);
        }
        // For side-effectful expressions (like i++, j--), wrap in closure
        $use = $this->makeUseClause();
        $last = array_pop($parts);
        $body = '';
        foreach ($parts as $p) {
            $body .= "{$p}; ";
        }
        return "(function(){$use} { {$body}return {$last}; })()";
    }

    /** Resolve a variable name through the let-rename map. */
    private function resolveVar(string $name): string
    {
        return $this->letRenames[$name] ?? $name;
    }

    private function emitCompoundAssignValueExpr(string $lhs, string $rhs, string $operator): string
    {
        if ($operator === '+=') {
            return self::OPS . "::add({$lhs}, {$rhs})";
        }
        if ($operator === '??=') {
            return "({$lhs} ?? {$rhs})";
        }
        if ($operator === '>>>=') {
            return "(((int)({$lhs}) & 0xFFFFFFFF) >> ((int)({$rhs}) & 0x1F))";
        }
        if ($operator === '/=') {
            return self::OPS . "::div({$lhs}, {$rhs})";
        }
        if ($operator === '%=') {
            return self::OPS . "::mod({$lhs}, {$rhs})";
        }

        $op = match ($operator) {
            '-=' => '-',
            '*=' => '*',
            '**=' => '**',
            '&=' => '&',
            '|=' => '|',
            '^=' => '^',
            '<<=' => '<<',
            '>>=' => '>>',
            default => throw new RuntimeException("Transpiler: unknown assign op {$operator}"),
        };

        return "({$lhs} {$op} {$rhs})";
    }

    private function makeUseClauseWith(array $extraVars): string
    {
        $vars = array_values(array_unique(array_merge(
            array_map(
                fn(string $name) => $this->resolveVar($name),
                $this->getAllScopeVars()
            ),
            $extraVars,
        )));

        if (empty($vars)) {
            return '';
        }

        $refs = array_map(fn(string $var) => '&$' . $var, $vars);
        return ' use (' . implode(', ', $refs) . ')';
    }

    private function emitCachedMemberAssign(MemberAssignExpr $e, string $objCode, string $val, bool $simpleBase): string
    {
        $namedKey = (!$e->computed && $e->property instanceof Identifier) ? $e->property->name : null;
        $keyExpr = $namedKey === null ? $this->emitExpr($e->property) : null;
        $objVar = $simpleBase ? $objCode : '$__mo' . $this->tmpId++;
        $keyVar = $keyExpr !== null ? '$__mk' . $this->tmpId++ : null;
        $currentVar = '$__mc' . $this->tmpId++;
        $rhsVar = '$__mr' . $this->tmpId++;
        $extraUse = [];

        if ($simpleBase && ($e->object instanceof Identifier || $e->object instanceof ThisExpr)) {
            $extraUse[] = ltrim($objCode, '$');
        }

        $use = $this->makeUseClauseWith($extraUse);
        $body = '';

        if (!$simpleBase) {
            $body .= "{$objVar} = {$objCode}; ";
        }
        if ($keyVar !== null) {
            $body .= "{$keyVar} = {$keyExpr}; ";
        }

        $escapedKey = $namedKey !== null ? $this->escapeStr($namedKey) : null;
        $readExpr = match (true) {
            $simpleBase && $namedKey !== null => "{$objVar}[{$escapedKey}]",
            $simpleBase => "{$objVar}[{$keyVar}]",
            $namedKey !== null => self::OPS . "::getNamedProp({$objVar}, {$escapedKey})",
            default => self::OPS . "::getProp({$objVar}, {$keyVar})",
        };
        $writeExpr = match (true) {
            $simpleBase && $namedKey !== null => fn(string $valueExpr): string => "({$objVar}[{$escapedKey}] = {$valueExpr})",
            $simpleBase => fn(string $valueExpr): string => "({$objVar}[{$keyVar}] = {$valueExpr})",
            $namedKey !== null => fn(string $valueExpr): string => self::OPS . "::setNamedProp({$objVar}, {$escapedKey}, {$valueExpr})",
            default => fn(string $valueExpr): string => self::OPS . "::setProp({$objVar}, {$keyVar}, {$valueExpr})",
        };

        if ($e->operator === '=') {
            $body .= 'return ' . $writeExpr($val) . ';';
            return "(function(){$use} { {$body} })()";
        }

        $body .= "{$currentVar} = {$readExpr}; ";

        if ($e->operator === '??=') {
            $body .= "if ({$currentVar} !== null) { return {$currentVar}; } ";
            $body .= 'return ' . $writeExpr($val) . ';';
            return "(function(){$use} { {$body} })()";
        }

        $body .= "{$rhsVar} = {$val}; ";
        $body .= 'return ' . $writeExpr($this->emitCompoundAssignValueExpr($currentVar, $rhsVar, $e->operator)) . ';';

        return "(function(){$use} { {$body} })()";
    }

    private function emitCachedMemberUpdate(UpdateExpr $e, bool $discardResult): string
    {
        $member = $e->argument;
        assert($member instanceof MemberExpr);

        $namedKey = (!$member->computed && $member->property instanceof Identifier) ? $member->property->name : null;
        $objCode = match (true) {
            $member->object instanceof ThisExpr => '$__this',
            $member->object instanceof FunctionExpr => $this->emitFunctionExpr($member->object, forceBox: true),
            default => $this->emitExpr($member->object),
        };
        $keyExpr = $namedKey === null ? $this->emitExpr($member->property) : null;
        $objVar = '$__mo' . $this->tmpId++;
        $keyVar = $keyExpr !== null ? '$__mk' . $this->tmpId++ : null;
        $oldVar = '$__u' . $this->tmpId++;
        $newVar = '$__un' . $this->tmpId++;
        $use = $this->makeUseClause();
        $body = "{$objVar} = {$objCode}; ";

        if ($keyVar !== null) {
            $body .= "{$keyVar} = {$keyExpr}; ";
        }

        $escapedKey = $namedKey !== null ? $this->escapeStr($namedKey) : null;
        $readExpr = $namedKey !== null
            ? self::OPS . "::getNamedProp({$objVar}, {$escapedKey})"
            : self::OPS . "::getProp({$objVar}, {$keyVar})";
        $writeExpr = $namedKey !== null
            ? self::OPS . "::setNamedProp({$objVar}, {$escapedKey}, {$newVar})"
            : self::OPS . "::setProp({$objVar}, {$keyVar}, {$newVar})";
        $sign = $e->operator === '++' ? '+' : '-';

        $body .= "{$oldVar} = {$readExpr}; ";
        $body .= "{$newVar} = " . self::OPS . "::toNumber({$oldVar}) {$sign} 1; ";
        $body .= "{$writeExpr}; ";
        if ($discardResult) {
            $body .= 'return null;';
        } else {
            $body .= 'return ' . ($e->prefix ? $newVar : $oldVar) . ';';
        }

        return "(function(){$use} { {$body} })()";
    }

    private function emitAssign(AssignExpr $e): string
    {
        $phpName = $this->resolveVar($e->name);
        $this->addVar($phpName);
        $val = $e->value instanceof FunctionExpr
            ? $this->emitFunctionExpr($e->value, $e->name)
            : $this->emitExpr($e->value);
        if ($e->operator === '=') {
            $this->trackVar($phpName, $this->inferType($e->value));
            return '($' . $phpName . ' = ' . $val . ')';
        }
        if ($e->operator === '+=') {
            $lhsType = $this->inferFromName($phpName);
            $rhsType = $this->inferType($e->value);
            // Fast path: both provably numeric
            if ($lhsType === TypeHint::Numeric && $rhsType === TypeHint::Numeric) {
                return '($' . $phpName . ' += ' . $val . ')';
            }
            // Fast path: lhs is string
            if ($lhsType === TypeHint::String) {
                return '($' . $phpName . ' .= (string)(' . $val . '))';
            }
            $ta = '$__t' . $this->tmpId++;
            $tb = '$__t' . $this->tmpId++;
            return '($' . $phpName . " = (is_string({$ta} = \${$phpName}) | is_string({$tb} = ({$val})) ? (string){$ta} . (string){$tb} : {$ta} + {$tb}))";
        }
        if ($e->operator === '??=') {
            return '($' . $phpName . ' = $' . $phpName . ' ?? ' . $val . ')';
        }
        if ($e->operator === '>>>=') {
            return '($' . $phpName . ' = ((int)$' . $phpName . ' & 0xFFFFFFFF) >> ((int)(' . $val . ') & 0x1F))';
        }
        if ($e->operator === '/=') {
            return '($' . $phpName . ' = ' . self::OPS . '::div($' . $phpName . ', ' . $val . '))';
        }
        if ($e->operator === '%=') {
            return '($' . $phpName . ' = ' . self::OPS . '::mod($' . $phpName . ', ' . $val . '))';
        }
        $op = match ($e->operator) {
            '-=' => '-',
            '*=' => '*',
            '**=' => '**',
            '&=' => '&',
            '|=' => '|',
            '^=' => '^',
            '<<=' => '<<',
            '>>=' => '>>',
            default => throw new RuntimeException("Transpiler: unknown assign op {$e->operator}"),
        };
        return '($' . $phpName . ' = $' . $phpName . " {$op} {$val})";
    }

    private function emitLogical(LogicalExpr $e): string
    {
        $tmp = '$__nc' . ($this->tmpId++);
        $left = $this->emitExpr($e->left);
        $right = $this->emitExpr($e->right);
        if ($e->operator === '??') {
            // Nullish coalescing: return right if left is null
            return "(({$tmp} = {$left}) === null ? {$right} : {$tmp})";
        }
        // Build inline truthiness test: assign left to $tmp, then test $tmp
        $leftType = $this->inferType($e->left);
        $test = match ($leftType) {
            TypeHint::Bool => "({$tmp} = {$left})",
            TypeHint::String => "(({$tmp} = {$left}) !== '')",
            TypeHint::Numeric => "(({$tmp} = {$left}) !== 0 && {$tmp} !== 0.0 && (!is_float({$tmp}) || !is_nan({$tmp})))",
            default => self::OPS . "::toBoolean({$tmp} = {$left})",
        };
        if ($e->operator === '||') {
            return "({$test} ? {$tmp} : {$right})";
        }
        if ($e->operator === '&&') {
            return "({$test} ? {$right} : {$tmp})";
        }
        return "({$left} {$e->operator} {$right})";
    }

    private function emitTypeof(TypeofExpr $e): string
    {
        // Fast path: known types at compile time
        if ($e->operand instanceof UndefinedLiteral) return "'undefined'";
        if ($e->operand instanceof NullLiteral) return "'object'";
        // typeof undeclaredVar → 'undefined' (must not throw)
        if ($e->operand instanceof Identifier && !in_array($e->operand->name, $this->getAllScopeVars(), true)) {
            return "'undefined'";
        }
        if ($e->operand instanceof ArrayLiteral || $e->operand instanceof ObjectLiteral) return "'object'";
        $hint = $this->inferType($e->operand);
        if ($hint === TypeHint::Numeric) return "'number'";
        if ($hint === TypeHint::String) return "'string'";
        if ($hint === TypeHint::Bool) return "'boolean'";
        if ($hint === TypeHint::Array_ || $hint === TypeHint::Object_) return "'object'";

        $v = '$__typeof' . ($this->tmpId++);
        $expr = $this->emitExpr($e->operand);
        // null represents both JS null and undefined at runtime — default to 'object'
        // (typeof null === 'object' in JS; undefined literals are handled above)
        return "(({$v} = {$expr}) === null ? 'object' "
            . ": (is_bool({$v}) ? 'boolean' "
            . ": (is_int({$v}) || is_float({$v}) ? 'number' "
            . ": (is_string({$v}) ? 'string' "
            . ": (({$v} instanceof \\Closure || {$v} instanceof " . self::JS_FUNCTION . ") ? 'function' : 'object')))))";
    }

    private function emitArray(ArrayLiteral $e): string
    {
        $hasSpread = false;
        foreach ($e->elements as $el) {
            if ($el instanceof SpreadElement) { $hasSpread = true; break; }
        }

        if (!$hasSpread) {
            $els = array_map(fn(Expr $el) => $this->emitExpr($el), $e->elements);
            return '[' . implode(', ', $els) . ']';
        }

        // Use array_merge for spread
        $parts = [];
        $current = [];
        foreach ($e->elements as $el) {
            if ($el instanceof SpreadElement) {
                if (!empty($current)) {
                    $parts[] = '[' . implode(', ', $current) . ']';
                    $current = [];
                }
                $arg = $this->emitExpr($el->argument);
                $hint = $this->inferType($el->argument);
                if ($hint === TypeHint::String) {
                    $parts[] = "mb_str_split({$arg}, 1, 'UTF-8')";
                } else {
                    $parts[] = "(is_string(\$__sp = ({$arg})) ? mb_str_split(\$__sp, 1, 'UTF-8') : \$__sp)";
                }
            } else {
                $current[] = $this->emitExpr($el);
            }
        }
        if (!empty($current)) {
            $parts[] = '[' . implode(', ', $current) . ']';
        }
        return 'array_merge(' . implode(', ', $parts) . ')';
    }

    private function emitObject(ObjectLiteral $e): string
    {
        if (empty($e->properties)) {
            return 'new ' . self::JS_OBJECT . '()';
        }

        $allStatic = true;
        foreach ($e->properties as $prop) {
            if ($prop->computed && $prop->computedKey !== null) {
                $allStatic = false;
                break;
            }
        }

        if ($allStatic) {
            $pairs = [];
            foreach ($e->properties as $prop) {
                $pairs[] = $this->escapeStr($prop->key) . ' => ' . $this->emitExpr($prop->value);
            }
            return 'new ' . self::JS_OBJECT . '([' . implode(', ', $pairs) . '])';
        }

        $pairs = [];
        foreach ($e->properties as $prop) {
            if ($prop->computed && $prop->computedKey !== null) {
                $pairs[] = '[' . $this->emitExpr($prop->computedKey) . ', ' . $this->emitExpr($prop->value) . ']';
            } else {
                $pairs[] = '[' . $this->escapeStr($prop->key) . ', ' . $this->emitExpr($prop->value) . ']';
            }
        }
        return self::OPS . '::objectLiteral([' . implode(', ', $pairs) . '])';
    }

    private function emitMember(MemberExpr $e): string
    {
        $obj = $e->object instanceof FunctionExpr
            ? $this->emitFunctionExpr($e->object, forceBox: true)
            : $this->emitExpr($e->object);
        $objType = $this->inferType($e->object);

        // Optional chaining: obj?.prop or chain continuation → temp var + null guard
        if ($e->optional || $e->optionalChain) {
            $tmp = '$__oc' . ($this->tmpId++);
            if (!$e->computed && $e->property instanceof Identifier) {
                $name = $e->property->name;
                // .length needs specialized handling even in optional chains
                if ($name === 'length') {
                    if ($objType === TypeHint::Array_) {
                        return "(({$tmp} = {$obj}) === null ? null : count({$tmp}))";
                    }
                    if ($objType === TypeHint::String) {
                        return "(({$tmp} = {$obj}) === null ? null : mb_strlen({$tmp}))";
                    }
                    return "(({$tmp} = {$obj}) === null ? null : (is_string({$tmp}) ? mb_strlen({$tmp}) : count({$tmp})))";
                }
                if ($objType === TypeHint::Object_) {
                    return "(({$tmp} = {$obj}) === null ? null : ({$tmp}->properties['{$name}'] ?? null))";
                }
                return "(({$tmp} = {$obj}) === null ? null : {$tmp}['{$name}'])";
            }
            $key = $this->emitExpr($e->property);
            return "(({$tmp} = {$obj}) === null ? null : {$tmp}[{$key}])";
        }

        if (!$e->computed && $e->property instanceof Identifier) {
            $name = $e->property->name;

            // Math constants
            if ($e->object instanceof Identifier && $e->object->name === 'Math') {
                return match ($name) {
                    'PI' => 'M_PI',
                    'E' => 'M_E',
                    'LN2' => 'M_LN2',
                    'LN10' => 'M_LN10',
                    'LOG2E' => 'M_LOG2E',
                    'LOG10E' => 'M_LOG10E',
                    'SQRT1_2' => 'M_SQRT1_2',
                    'SQRT2' => 'M_SQRT2',
                    default => "\${$e->object->name}['{$name}']",
                };
            }

            // Number constants
            if ($e->object instanceof Identifier && $e->object->name === 'Number') {
                return match ($name) {
                    'MAX_SAFE_INTEGER' => '9007199254740991',
                    'MIN_SAFE_INTEGER' => '-9007199254740991',
                    'POSITIVE_INFINITY' => 'INF',
                    'NEGATIVE_INFINITY' => '-INF',
                    'NaN' => 'NAN',
                    'EPSILON' => '2.2204460492503131e-16',
                    default => "\${$e->object->name}['{$name}']",
                };
            }

            // Object static method aliases (e.g. var keys = Object.keys)
            if ($e->object instanceof Identifier && $e->object->name === 'Object') {
                return match ($name) {
                    'keys' => 'static function($value = null) { return ' . self::OPS . '::keys($value); }',
                    'values' => 'static function($value = null) { return ' . self::OPS . '::values($value); }',
                    'entries' => 'static function($value = null) { return ' . self::OPS . '::entries($value); }',
                    'assign' => 'static function($target = null, ...$sources) { return ' . self::OPS . '::objectAssign($target, ...$sources); }',
                    'is' => 'static function($a = null, $b = null) { return ' . self::OPS . '::objectIs($a, $b); }',
                    'create' => 'static function($proto = null) { return ' . self::OPS . '::objectCreate($proto); }',
                    'freeze' => 'static function($obj = null) { return $obj; }',
                    default => "\${$e->object->name}['{$name}']",
                };
            }

            // .length → count() for arrays, mb_strlen() for strings
            if ($name === 'length') {
                if ($objType === TypeHint::Array_) {
                    return "count({$obj})";
                }
                if ($objType === TypeHint::String) {
                    return "mb_strlen({$obj}, 'UTF-8')";
                }
                return self::OPS . '::getLength(' . $obj . ')';
            }

            if ($objType === TypeHint::Object_) {
                return "({$obj}->properties['{$name}'] ?? null)";
            }

            return "{$obj}['{$name}']";
        }

        // Computed: obj[expr]
        $key = $this->emitExpr($e->property);
        if ($objType === TypeHint::Object_) {
            return "({$obj}->properties[{$key}] ?? null)";
        }
        return "{$obj}[{$key}]";
    }

    private function emitMemberAssign(MemberAssignExpr $e): string
    {
        $val = $this->emitExpr($e->value);
        $objCode = match (true) {
            $e->object instanceof ThisExpr => '$__this',
            $e->object instanceof FunctionExpr => $this->emitFunctionExpr($e->object, forceBox: true),
            default => $this->emitExpr($e->object),
        };
        $simpleBase = $e->object instanceof Identifier || $e->object instanceof ThisExpr;

        if (!$e->computed && $e->property instanceof Identifier) {
            $key = "'" . $e->property->name . "'";
        } else {
            $key = $this->emitExpr($e->property);
        }

        if ($e->operator === '=') {
            return "({$objCode}[{$key}] = {$val})";
        }

        if (!$simpleBase || $e->computed) {
            return $this->emitCachedMemberAssign($e, $objCode, $val, $simpleBase);
        }

        // Compound assignment: obj[key] += val
        if ($e->operator === '+=') {
            $rhsType = $this->inferType($e->value);
            // Fast path: rhs is provably numeric (and obj[key] is used in numeric context)
            if ($rhsType === TypeHint::Numeric) {
                $ta = '$__t' . $this->tmpId++;
                return "({$objCode}[{$key}] = (is_string({$ta} = {$objCode}[{$key}]) ? {$ta} . (string)({$val}) : {$ta} + ({$val})))";
            }
            $ta = '$__t' . $this->tmpId++;
            $tb = '$__t' . $this->tmpId++;
            return "({$objCode}[{$key}] = (is_string({$ta} = {$objCode}[{$key}]) | is_string({$tb} = ({$val})) ? (string){$ta} . (string){$tb} : {$ta} + {$tb}))";
        }
        if ($e->operator === '??=') {
            return "({$objCode}[{$key}] = {$objCode}[{$key}] ?? {$val})";
        }
        if ($e->operator === '>>>=') {
            return "({$objCode}[{$key}] = ((int){$objCode}[{$key}] & 0xFFFFFFFF) >> ((int)({$val}) & 0x1F))";
        }
        if ($e->operator === '/=') {
            return "({$objCode}[{$key}] = " . self::OPS . "::div({$objCode}[{$key}], {$val}))";
        }
        if ($e->operator === '%=') {
            return "({$objCode}[{$key}] = " . self::OPS . "::mod({$objCode}[{$key}], {$val}))";
        }
        $op = match ($e->operator) {
            '-=' => '-', '*=' => '*', '**=' => '**',
            '&=' => '&', '|=' => '|', '^=' => '^',
            '<<=' => '<<', '>>=' => '>>',
            default => throw new RuntimeException("Unknown member assign op {$e->operator}"),
        };
        return "({$objCode}[{$key}] = {$objCode}[{$key}] {$op} {$val})";
    }

    private function emitNew(NewExpr $e): string
    {
        // new Date(...)
        if ($e->callee instanceof Identifier && $e->callee->name === 'Date') {
            $args = array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments);
            return 'new \\ScriptLite\\Transpiler\\Runtime\\TrDate(' . implode(', ', $args) . ')';
        }

        // new RegExp(pattern, flags)
        if ($e->callee instanceof Identifier && $e->callee->name === 'RegExp') {
            return self::OPS . '::createRegex(' . implode(', ', array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments)) . ')';
        }

        $callee = $e->callee instanceof FunctionExpr
            ? $this->emitFunctionExpr($e->callee, forceBox: true)
            : $this->emitExpr($e->callee);
        $args = $this->hasSpreadArg($e->arguments)
            ? $this->emitSpreadArgs($e->arguments)
            : '[' . implode(', ', array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments)) . ']';

        return self::OPS . '::construct(' . $callee . ', ' . $args . ')';
    }

    private function emitTemplateLiteral(TemplateLiteral $e): string
    {
        $parts = [];
        if ($e->quasis[0] !== '') {
            $parts[] = $this->escapeStr($e->quasis[0]);
        }

        for ($i = 0; $i < count($e->expressions); $i++) {
            $expr = $this->emitExpr($e->expressions[$i]);
            // JS-style string coercion: true→"true", false→"false", null→"null"
            if ($e->expressions[$i] instanceof UndefinedLiteral) {
                $parts[] = "'undefined'";
            } elseif ($e->expressions[$i] instanceof StringLiteral || $e->expressions[$i] instanceof TemplateLiteral) {
                $parts[] = $expr;
            } else {
                $parts[] = "(is_bool(\$__tv = ({$expr})) ? (\$__tv ? 'true' : 'false') : (\$__tv === null ? 'null' : (string)\$__tv))";
            }
            if ($e->quasis[$i + 1] !== '') {
                $parts[] = $this->escapeStr($e->quasis[$i + 1]);
            }
        }

        if (empty($parts)) {
            return "''";
        }

        return '(' . implode(' . ', $parts) . ')';
    }

    private function emitRegex(RegexLiteral $e): string
    {
        $pcre = $this->jsToPcre($e->pattern, $e->flags);
        $isGlobal = str_contains($e->flags, 'g') ? 'true' : 'false';
        $isIgnoreCase = str_contains($e->flags, 'i') ? 'true' : 'false';
        $source = $this->escapeStr($e->pattern);
        $flags = $this->escapeStr($e->flags);
        return "['__re' => true, 'pcre' => {$pcre}, 'source' => {$source}, 'flags' => {$flags}, 'g' => {$isGlobal}, 'global' => {$isGlobal}, 'ignoreCase' => {$isIgnoreCase}]";
    }

    // ───────────────── Function Calls ─────────────────

    private function emitCall(CallExpr $e): string
    {
        $useOpt = $e->optional || $e->optionalChain;

        // Method call: obj.method(args)
        if ($e->callee instanceof MemberExpr && !$e->callee->computed && $e->callee->property instanceof Identifier) {
            return $this->emitMethodCall($e->callee, $e->arguments, $useOpt);
        }

        // Built-in global functions (only when no spread args, and not optional)
        if (!$useOpt && $e->callee instanceof Identifier && !$this->hasSpreadArg($e->arguments)) {
            $args = array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments);
            $mapped = match ($e->callee->name) {
                'isNaN' => 'is_nan(' . self::OPS . '::toNumber(' . $args[0] . '))',
                'isFinite' => 'is_finite(' . self::OPS . '::toNumber(' . $args[0] . '))',
                'parseInt' => self::OPS . '::parseInt(' . implode(', ', $args) . ')',
                'parseFloat' => '(is_numeric($__pf = (string)(' . $args[0] . ')) ? ($__pf + 0) : (preg_match(\'/^([+-]?(?:\\d+\\.?\\d*|\\.\\d+)(?:[eE][+-]?\\d+)?)/\', trim($__pf), $__pfm) ? ($__pfm[1] + 0) : NAN))',
                'encodeURIComponent' => 'rawurlencode((string)(' . $args[0] . '))',
                'decodeURIComponent' => 'rawurldecode((string)(' . $args[0] . '))',
                'encodeURI' => 'str_replace([\'%3A\', \'%2F\', \'%3F\', \'%23\', \'%5B\', \'%5D\', \'%40\', \'%21\', \'%24\', \'%26\', \'%27\', \'%28\', \'%29\', \'%2A\', \'%2B\', \'%2C\', \'%3B\', \'%3D\'], [\':\', \'/\', \'?\', \'#\', \'[\', \']\', \'@\', \'!\', \'$\', \'&\', "\'", \'(\', \')\', \'*\', \'+\', \',\', \';\', \'=\'], rawurlencode((string)(' . $args[0] . ')))',
                'decodeURI' => 'rawurldecode((string)(' . $args[0] . '))',
                default => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // Global function call
        $callee = $this->emitExpr($e->callee);
        // Wrap function expressions in parens for IIFE: (function(){...})(args)
        if ($e->callee instanceof FunctionExpr) {
            $callee = '(' . $callee . ')';
        }

        // Optional call: wrap callee in null guard
        if ($useOpt) {
            $tmp = '$__oc' . ($this->tmpId++);
            $args = array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments);
            $argStr = implode(', ', $args);
            return "(({$tmp} = {$callee}) === null ? null : {$tmp}({$argStr}))";
        }

        $hasSpread = false;
        foreach ($e->arguments as $a) {
            if ($a instanceof SpreadElement) { $hasSpread = true; break; }
        }

        if (!$hasSpread) {
            $args = array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments);
            return $callee . '(' . implode(', ', $args) . ')';
        }

        // Spread call: build merged array and splat
        $merged = $this->emitSpreadArgs($e->arguments);
        return $callee . '(...' . $merged . ')';
    }

    /** @param array<Expr|SpreadElement> $args */
    /** @param array<Expr|SpreadElement> $args */
    private function hasSpreadArg(array $args): bool
    {
        foreach ($args as $a) {
            if ($a instanceof SpreadElement) { return true; }
        }
        return false;
    }

    private function emitSpreadArgs(array $args): string
    {
        $parts = [];
        $current = [];
        foreach ($args as $arg) {
            if ($arg instanceof SpreadElement) {
                if (!empty($current)) {
                    $parts[] = '[' . implode(', ', $current) . ']';
                    $current = [];
                }
                $parts[] = $this->emitExpr($arg->argument);
            } else {
                $current[] = $this->emitExpr($arg);
            }
        }
        if (!empty($current)) {
            $parts[] = '[' . implode(', ', $current) . ']';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        return 'array_merge(' . implode(', ', $parts) . ')';
    }

    /** @param Expr[] $args */
    private function emitMethodCall(MemberExpr $callee, array $args, bool $optional = false): string
    {
        $method = $callee->property->name;
        $obj = $callee->object instanceof FunctionExpr
            ? $this->emitFunctionExpr($callee->object, forceBox: true)
            : $this->emitExpr($callee->object);
        $emitArgs = fn() => array_map(fn(Expr $a) => $this->emitExpr($a), $args);
        $objectType = $this->inferType($callee->object);

        // Optional chain: guard the receiver against null
        if ($optional) {
            $tmp = '$__oc' . ($this->tmpId++);
            $a = $emitArgs();
            $dynamicMethod = $objectType === TypeHint::Object_
                ? "({$tmp}->properties['{$method}'] ?? null)"
                : "{$tmp}['{$method}']";
            return "(({$tmp} = {$obj}) === null ? null : {$dynamicMethod}(" . implode(', ', $a) . '))';
        }

        $dynamicMethod = $objectType === TypeHint::Object_
            ? "({$obj}->properties['{$method}'] ?? null)"
            : "{$obj}['{$method}']";

        // ── Math.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Math') {
            $a = $emitArgs();
            return match ($method) {
                'floor' => '(int)floor(' . $a[0] . ')',
                'ceil' => '(int)ceil(' . $a[0] . ')',
                'abs' => 'abs(' . $a[0] . ')',
                'max' => 'max(' . implode(', ', $a) . ')',
                'min' => 'min(' . implode(', ', $a) . ')',
                'round' => '(int)round(' . $a[0] . ')',
                'random' => '(mt_rand() / mt_getrandmax())',
                'sqrt' => 'sqrt(' . $a[0] . ')',
                'pow' => '((' . $a[0] . ') ** (' . $a[1] . '))',
                'sin' => 'sin(' . $a[0] . ')',
                'cos' => 'cos(' . $a[0] . ')',
                'tan' => 'tan(' . $a[0] . ')',
                'asin' => 'asin(' . $a[0] . ')',
                'acos' => 'acos(' . $a[0] . ')',
                'atan' => 'atan(' . $a[0] . ')',
                'atan2' => 'atan2(' . $a[0] . ', ' . $a[1] . ')',
                'log' => 'log(' . $a[0] . ')',
                'log2' => 'log(' . $a[0] . ', 2)',
                'log10' => 'log10(' . $a[0] . ')',
                'exp' => 'exp(' . $a[0] . ')',
                'cbrt' => '((' . $a[0] . ') ** (1/3))',
                'hypot' => 'sqrt((' . $a[0] . ') ** 2 + (' . $a[1] . ') ** 2)',
                'sign' => '((' . $a[0] . ') <=> 0)',
                'trunc' => '(int)(' . $a[0] . ')',
                'clz32' => '(' . $a[0] . ' === 0 ? 32 : (31 - (int)floor(log((' . $a[0] . ') & 0xFFFFFFFF, 2))))',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── console.log ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'console' && $method === 'log') {
            $a = $emitArgs();
            return '(($__out .= implode(" ", array_map("strval", [' . implode(', ', $a) . '])) . "\\n") ? null : null)';
        }

        // ── Date.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Date') {
            $a = $emitArgs();
            return match ($method) {
                'now' => '(int)(microtime(true) * 1000)',
                'parse' => '(int)(strtotime(' . $a[0] . ') * 1000)',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── Array.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Array') {
            $a = $emitArgs();
            return match ($method) {
                'isArray' => 'is_array(' . $a[0] . ')',
                'from' => 'array_values((array)(' . $a[0] . '))',
                'of' => '[' . implode(', ', $a) . ']',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── Object.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Object') {
            $a = $emitArgs();
            return match ($method) {
                'keys' => self::OPS . '::keys(' . $a[0] . ')',
                'values' => self::OPS . '::values(' . $a[0] . ')',
                'entries' => self::OPS . '::entries(' . $a[0] . ')',
                'assign' => self::OPS . '::objectAssign(' . implode(', ', $a) . ')',
                'is' => self::OPS . '::objectIs(' . $a[0] . ', ' . $a[1] . ')',
                'create' => self::OPS . '::objectCreate(' . $a[0] . ')',
                'freeze' => $a[0],  // freeze is a no-op in transpiled path (no enforcement)
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── Number.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Number') {
            $a = $emitArgs();
            $t = '$__t' . $this->tmpId++;
            return match ($method) {
                'isInteger' => "(is_int({$t} = {$a[0]}) || (is_float({$t}) && {$t} == (int){$t}))",
                'isFinite' => "(is_int({$t} = {$a[0]}) || (is_float({$t}) && is_finite({$t})))",
                'isNaN' => "(is_float({$t} = {$a[0]}) && is_nan({$t}))",
                'parseInt' => self::OPS . '::parseInt(' . implode(', ', $a) . ')',
                'parseFloat' => '(is_numeric($__pf = (string)(' . $a[0] . ')) ? ($__pf + 0) : (preg_match(\'/^([+-]?(?:\\d+\\.?\\d*|\\.\\d+)(?:[eE][+-]?\\d+)?)/\', trim($__pf), $__pfm) ? ($__pfm[1] + 0) : NAN))',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── JSON.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'JSON') {
            $a = $emitArgs();
            return match ($method) {
                'stringify' => self::OPS . '::jsonStringify(' . $a[0] . ')',
                'parse' => self::OPS . '::jsonParse(' . $a[0] . ')',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── String.* static methods ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'String') {
            $a = $emitArgs();
            return match ($method) {
                'fromCharCode' => 'implode("", array_map(fn($c) => mb_chr((int)$c, "UTF-8"), [' . implode(', ', $a) . ']))',
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }

        // ── Number methods (.toFixed, .toPrecision, .toExponential, .toString) ──
        if ($method === 'toFixed' || $method === 'toPrecision' || $method === 'toExponential' || $method === 'toString') {
            $a = $emitArgs();
            return match ($method) {
                'toFixed' => 'number_format((float)(' . $obj . '), (int)(' . ($a[0] ?? '0') . '), \'.\', \'\')',
                'toPrecision' => self::OPS . '::toPrecision((float)(' . $obj . '), (int)(' . ($a[0] ?? '0') . '))',
                'toExponential' => self::OPS . '::toExponential((float)(' . $obj . '), ' . ($a[0] ?? 'null') . ')',
                'toString' => isset($a[0])
                    ? 'base_convert((string)(int)(' . $obj . '), 10, (int)(' . $a[0] . '))'
                    : '(string)(' . $obj . ')',
            };
        }

        // ── Regex .test() / .exec() ──
        $execHelper = self::OPS . '::regexExec';
        if ($callee->object instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($callee->object->pattern, $callee->object->flags);
            $a = $emitArgs();
            return match ($method) {
                'test' => '(bool)preg_match(' . $pcre . ', ' . $a[0] . ')',
                'exec' => "{$execHelper}({$pcre}, {$a[0]})",
                default => $dynamicMethod . '(' . implode(', ', $a) . ')',
            };
        }
        // Regex stored in variable: detect ['__re' => true] pattern
        if ($method === 'test' || $method === 'exec') {
            $a = $emitArgs();
            $t = '$__t' . $this->tmpId++;
            if ($method === 'test') {
                return "(is_array({$t} = {$obj}) && ({$t}['__re'] ?? false) ? (bool)preg_match({$t}['pcre'], {$a[0]}) : {$t}['{$method}']({$a[0]}))";
            }
            return "(is_array({$t} = {$obj}) && ({$t}['__re'] ?? false) ? {$execHelper}({$t}['pcre'], {$a[0]}) : {$t}['{$method}']({$a[0]}))";
        }

        $a = $emitArgs();
        $use = $this->makeUseClause();

        // ── Array methods ──
        return match ($method) {
            'push' => "({$obj}[] = {$a[0]})",
            'pop' => 'array_pop(' . $obj . ')',
            'shift' => 'array_shift(' . $obj . ')',
            'unshift' => 'array_unshift(' . $obj . ', ' . $a[0] . ')',
            'filter' => $this->callbackUsesIndex($args) ? 'array_values(array_filter(' . $obj . ', ' . $a[0] . ', ARRAY_FILTER_USE_BOTH))' : 'array_values(array_filter(' . $obj . ', ' . $a[0] . '))',
            'map' => $this->callbackUsesIndex($args) ? 'array_map(' . $a[0] . ', ' . $obj . ', array_keys(' . $obj . '))' : 'array_map(' . $a[0] . ', ' . $obj . ')',
            'reduce' => isset($a[1])
                ? 'array_reduce(' . $obj . ', ' . $a[0] . ', ' . $a[1] . ')'
                : "(function(){$use} { \$__r = array_values({$obj}); return array_reduce(array_slice(\$__r, 1), {$a[0]}, \$__r[0]); })()",
            'reduceRight' => isset($a[1])
                ? 'array_reduce(array_reverse(' . $obj . '), ' . $a[0] . ', ' . $a[1] . ')'
                : "(function(){$use} { \$__r = array_reverse({$obj}); return array_reduce(array_slice(\$__r, 1), {$a[0]}, \$__r[0]); })()",
            'forEach' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { ({$a[0]})(\$__v, \$__i); } })()",
            'every' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (!({$a[0]})(\$__v, \$__i)) return false; } return true; })()",
            'some' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return true; } return false; })()",
            'find' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__v; } return null; })()",
            'findIndex' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__i; } return -1; })()",
            'join' => 'implode(' . ($a[0] ?? "','") . ', ' . $obj . ')',
            'concat' => $this->emitConcat($obj, $a, $objectType),
            'indexOf' => $this->emitIndexOf($obj, $a),
            'includes' => $this->emitIncludes($obj, $a),
            'slice' => $this->emitSlice($obj, $a),
            'sort' => isset($a[0])
                ? "(function(){$use} { usort({$obj}, {$a[0]}); return {$obj}; })()"
                : "(function(){$use} { usort({$obj}, function(\$a, \$b) { return strcmp((string)\$a, (string)\$b); }); return {$obj}; })()",
            'reverse' => 'array_reverse(' . $obj . ')',
            'flat' => $this->emitFlat($obj, $a),
            'splice' => count($a) > 2
                ? 'array_splice(' . $obj . ', ' . $a[0] . ', ' . $a[1] . ', [' . implode(', ', array_slice($a, 2)) . '])'
                : 'array_splice(' . $obj . ', ' . implode(', ', $a) . ')',
            'fill' => "(function(){$use} { for (\$__i = " . ($a[1] ?? '0') . "; \$__i < " . ($a[2] ?? 'count(' . $obj . ')') . "; \$__i++) { {$obj}[\$__i] = {$a[0]}; } return {$obj}; })()",
            'findLast' => "(function(){$use} { foreach (array_reverse({$obj}, true) as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__v; } return null; })()",
            'findLastIndex' => "(function(){$use} { foreach (array_reverse({$obj}, true) as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__i; } return -1; })()",
            'flatMap' => "array_merge(...array_map(fn(\$__v, \$__i) => (array)(({$a[0]})(\$__v, \$__i)), {$obj}, array_keys({$obj})))",

            // ── String methods ──
            'split' => $this->emitStrSplit($obj, $a, $args),
            'toUpperCase' => 'mb_strtoupper(' . $obj . ', \'UTF-8\')',
            'toLowerCase' => 'mb_strtolower(' . $obj . ', \'UTF-8\')',
            'trim' => 'trim(' . $obj . ')',
            'trimStart' => 'ltrim(' . $obj . ')',
            'trimEnd' => 'rtrim(' . $obj . ')',
            'charAt' => $this->emitCharAt($obj, $a),
            'charCodeAt' => $this->emitCharCodeAt($obj, $a),
            'substring' => $this->emitSubstring($obj, $a),
            'startsWith' => $this->emitStartsWith($obj, $a),
            'endsWith' => $this->emitEndsWith($obj, $a),
            'repeat' => 'str_repeat(' . $obj . ', (int)' . $a[0] . ')',
            'replace' => $this->emitStrReplace($obj, $a, $args),
            'replaceAll' => 'str_replace(' . $a[0] . ', ' . $a[1] . ', ' . $obj . ')',
            'match' => $this->emitStrMatch($obj, $a, $args),
            'matchAll' => $this->emitStrMatchAll($obj, $a, $args),
            'search' => $this->emitStrSearch($obj, $a, $args),
            'padStart' => $this->emitPadStart($obj, $a),
            'padEnd' => $this->emitPadEnd($obj, $a),
            'lastIndexOf' => $this->emitLastIndexOf($obj, $a),
            'at' => self::OPS . '::at(' . $obj . ', (int)(' . $a[0] . '))',

            // ── Object methods ──
            'hasOwnProperty' => $objectType === TypeHint::Object_
                ? "array_key_exists({$a[0]}, {$obj}->properties)"
                : self::OPS . '::hasOwn(' . $obj . ', ' . $a[0] . ')',

            // ── Unknown method → property access returning function ──
            default => $dynamicMethod . '(' . implode(', ', $a) . ')',
        };
    }

    // ───────────────── String method helpers ─────────────────

    private function emitSlice(string $obj, array $a): string
    {
        $start = $a[0];
        $t = '$__t' . $this->tmpId++;
        // For strings: handle negative indices per JS spec using Ops::slice
        $strSlice = self::OPS . "::strSlice({$t}, (int)({$start})" . (isset($a[1]) ? ", (int)({$a[1]})" : "") . ")";
        // For arrays: use array_slice with negative support
        $arrSlice = isset($a[1])
            ? "array_slice({$t}, {$start}, {$a[1]} - {$start})"
            : "array_slice({$t}, {$start})";
        return "(is_string({$t} = {$obj}) ? {$strSlice} : {$arrSlice})";
    }

    private function emitStrSplit(string $obj, array $a, array $argExprs = []): string
    {
        // No arguments: JS split() returns [originalString]
        if (!isset($a[0])) {
            return "[{$obj}]";
        }
        $sep = $a[0];
        $limit = $a[1] ?? null;

        // Fast path: separator is a string literal — no IIFE needed
        if (isset($argExprs[0]) && $argExprs[0] instanceof StringLiteral) {
            $sepValue = $argExprs[0]->value;
            if ($sepValue === '') {
                return $limit !== null
                    ? "array_slice(mb_str_split({$obj}), 0, (int)({$limit}))"
                    : "mb_str_split({$obj})";
            }
            return $limit !== null
                ? "array_slice(explode({$sep}, {$obj}), 0, (int)({$limit}))"
                : "explode({$sep}, {$obj})";
        }

        // Slow path: separator might be a regex — need IIFE for branching
        $use = $this->makeUseClause();
        return "(function(){$use} { \$__s = {$sep}; \$__o = {$obj}; "
            . "if (is_array(\$__s) && (\$__s['__re'] ?? false)) return preg_split(\$__s['pcre'], \$__o" . ($limit !== null ? ", (int)({$limit})" : "") . "); "
            . "if (\$__s === '') return " . ($limit !== null ? "array_slice(mb_str_split(\$__o), 0, (int)({$limit}))" : "mb_str_split(\$__o)") . "; "
            . "return " . ($limit !== null ? "array_slice(explode(\$__s, \$__o), 0, (int)({$limit}))" : "explode(\$__s, \$__o)") . "; })()";
    }

    private function emitStrReplace(string $obj, array $a, array $argExprs): string
    {
        $isCallback = $argExprs[1] instanceof FunctionExpr;
        // When the replacement is not a literal function or string, emit a runtime check
        $maybeCallback = !$isCallback && !($argExprs[1] instanceof StringLiteral);

        if ($argExprs[0] instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($argExprs[0]->pattern, $argExprs[0]->flags);
            $limit = str_contains($argExprs[0]->flags, 'g') ? '-1' : '1';
            if ($isCallback) {
                return "preg_replace_callback({$pcre}, function(\$__m) { return (string)({$a[1]})(...\$__m); }, {$obj}, {$limit})";
            }
            if ($maybeCallback) {
                $use = $this->makeUseClause();
                return "(function(){$use} { \$__cb = {$a[1]}; return is_callable(\$__cb) "
                    . "? preg_replace_callback({$pcre}, function(\$__m) use (&\$__cb) { return (string)\$__cb(...\$__m); }, {$obj}, {$limit}) "
                    . ": preg_replace({$pcre}, \$__cb, {$obj}, {$limit}); })()";
            }
            return "preg_replace({$pcre}, {$a[1]}, {$obj}, {$limit})";
        }
        $use = $this->makeUseClause();
        if ($isCallback) {
            return "(function(){$use} { \$__p = strpos({$obj}, {$a[0]}); "
                . "return \$__p === false ? {$obj} : substr({$obj}, 0, \$__p) . (string)({$a[1]})({$a[0]}, \$__p, {$obj}) . substr({$obj}, \$__p + strlen({$a[0]})); })()";
        }
        if ($maybeCallback) {
            return "(function(){$use} { \$__cb = {$a[1]}; \$__p = strpos({$obj}, {$a[0]}); "
                . "if (\$__p === false) return {$obj}; "
                . "return is_callable(\$__cb) "
                . "? substr({$obj}, 0, \$__p) . (string)\$__cb({$a[0]}, \$__p, {$obj}) . substr({$obj}, \$__p + strlen({$a[0]})) "
                . ": substr({$obj}, 0, \$__p) . \$__cb . substr({$obj}, \$__p + strlen({$a[0]})); })()";
        }
        return "(function(){$use} { \$__p = strpos({$obj}, {$a[0]}); "
            . "return \$__p === false ? {$obj} : substr({$obj}, 0, \$__p) . {$a[1]} . substr({$obj}, \$__p + strlen({$a[0]})); })()";
    }

    private function emitStrMatch(string $obj, array $a, array $argExprs): string
    {
        if ($argExprs[0] instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($argExprs[0]->pattern, $argExprs[0]->flags);
            if (str_contains($argExprs[0]->flags, 'g')) {
                return "(preg_match_all({$pcre}, {$obj}, \$__m) > 0 ? \$__m[0] : null)";
            }
            return "(preg_match({$pcre}, {$obj}, \$__m) ? \$__m : null)";
        }
        return 'null';
    }

    private function emitStrMatchAll(string $obj, array $a, array $argExprs): string
    {
        if ($argExprs[0] instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($argExprs[0]->pattern, $argExprs[0]->flags);
            return "(preg_match_all({$pcre}, {$obj}, \$__m, PREG_SET_ORDER) > 0 ? \$__m : [])";
        }
        return '[]';
    }

    private function emitStrSearch(string $obj, array $a, array $argExprs): string
    {
        if ($argExprs[0] instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($argExprs[0]->pattern, $argExprs[0]->flags);
            return "(preg_match({$pcre}, {$obj}, \$__m, PREG_OFFSET_CAPTURE) ? \$__m[0][1] : -1)";
        }
        return '-1';
    }

    private function emitFlat(string $obj, array $a): string
    {
        $depth = $a[0] ?? '1';
        return "(function(\$arr, \$d) { \$f = function(\$a, \$d) use (&\$f) { \$r = []; foreach (\$a as \$v) { if (is_array(\$v) && \$d > 0) \$r = array_merge(\$r, \$f(\$v, \$d-1)); else \$r[] = \$v; } return \$r; }; return \$f(\$arr, \$d); })({$obj}, {$depth})";
    }

    private function emitCharAt(string $obj, array $a): string
    {
        $t = '$__t' . $this->tmpId++;
        $t2 = '$__t' . $this->tmpId++;
        // JS charAt: negative or OOB index returns '', not wrap-around
        return "(({$t} = (int)({$a[0]})) < 0 || {$t} >= mb_strlen({$t2} = {$obj}, 'UTF-8') ? '' : mb_substr({$t2}, {$t}, 1, 'UTF-8'))";
    }

    private function emitCharCodeAt(string $obj, array $a): string
    {
        $t = '$__t' . $this->tmpId++;
        return "(({$t} = mb_substr({$obj}, (int)({$a[0]}), 1, 'UTF-8')) === '' || {$t} === false ? NAN : mb_ord({$t}, 'UTF-8'))";
    }

    private function emitSubstring(string $obj, array $a): string
    {
        $use = $this->makeUseClause();
        if (!isset($a[1])) {
            return "mb_substr({$obj}, (int)({$a[0]}), null, 'UTF-8')";
        }
        // JS substring swaps if start > end
        return "(function(){$use} { \$__s = (int)({$a[0]}); \$__e = (int)({$a[1]}); if (\$__s > \$__e) [\$__s, \$__e] = [\$__e, \$__s]; return mb_substr({$obj}, \$__s, \$__e - \$__s, 'UTF-8'); })()";
    }

    private function emitStartsWith(string $obj, array $a): string
    {
        if (!isset($a[1])) {
            return 'str_starts_with(' . $obj . ', ' . $a[0] . ')';
        }
        // startsWith with position: check from offset
        return "(mb_substr({$obj}, (int)({$a[1]}), mb_strlen({$a[0]}, 'UTF-8'), 'UTF-8') === {$a[0]})";
    }

    private function emitEndsWith(string $obj, array $a): string
    {
        if (!isset($a[1])) {
            return 'str_ends_with(' . $obj . ', ' . $a[0] . ')';
        }
        // endsWith with endPosition: check within first N chars
        return "str_ends_with(mb_substr({$obj}, 0, (int)({$a[1]}), 'UTF-8'), {$a[0]})";
    }

    private function emitIndexOf(string $obj, array $a): string
    {
        $t = '$__t' . $this->tmpId++;
        if (!isset($a[1])) {
            // No fromIndex — dispatch on string vs array
            return "(is_string({$t} = {$obj}) ? (({$t} = mb_strpos({$t}, {$a[0]}, 0, 'UTF-8')) === false ? -1 : {$t}) : (({$t} = array_search({$a[0]}, {$t}, true)) === false ? -1 : {$t}))";
        }
        // With fromIndex
        $t2 = '$__t' . $this->tmpId++;
        return "(is_string({$t} = {$obj}) ? (({$t2} = mb_strpos({$t}, {$a[0]}, max(0, (int)({$a[1]})), 'UTF-8')) === false ? -1 : {$t2}) : (({$t2} = array_search({$a[0]}, array_slice({$t}, (int)({$a[1]}), null, true), true)) === false ? -1 : {$t2}))";
    }

    private function emitIncludes(string $obj, array $a): string
    {
        $t = '$__t' . $this->tmpId++;
        if (!isset($a[1])) {
            return "(is_string({$t} = {$obj}) ? str_contains({$t}, {$a[0]}) : in_array({$a[0]}, {$t}, true))";
        }
        // With fromIndex
        return "(is_string({$t} = {$obj}) ? str_contains(mb_substr({$t}, (int)({$a[1]}), null, 'UTF-8'), {$a[0]}) : in_array({$a[0]}, array_slice({$t}, (int)({$a[1]})), true))";
    }

    /**
     * Check if an emitted expression is a simple variable (no side effects, cheap to re-evaluate).
     * Returns true for `$foo`, false for `$foo['bar']`, `func()`, etc.
     */
    private function isSimpleVar(string $emitted): bool
    {
        // Simple variable ($foo) or numeric literal (1, 3.14, -5)
        return (bool) preg_match('/^\$\w+$|^-?\d+(\.\d+)?$/', $emitted);
    }

    /**
     * Return [$assign, $ref] for use in inline guards.
     * For simple vars: $assign = $ref = '$varName' (no temp var needed).
     * For complex exprs: $assign = '$__t = expr', $ref = '$__t' (caches result).
     */
    private function inlineGuardVar(string $emitted, string $prefix): array
    {
        if ($this->isSimpleVar($emitted)) {
            return [$emitted, $emitted];
        }
        $t = '$__' . $prefix . $this->tmpId++;
        return ["({$t} = {$emitted})", $t];
    }

    /** Check if the first arg (callback) to map/filter/etc uses the index parameter. */
    private function callbackUsesIndex(array $args): bool
    {
        if (!isset($args[0]) || !$args[0] instanceof FunctionExpr) {
            return true; // unknown callback shape — assume it needs index
        }
        return count($args[0]->params) >= 2;
    }

    private function emitConcat(string $obj, array $a, ?TypeHint $objType = null): string
    {
        // Helper: wrap arg for array_merge — cache in temp var to avoid re-evaluation
        $wrapArg = function (string $x): string {
            // Simple variables and literals don't need caching
            if (preg_match('/^\$\w+$/', $x) || preg_match('/^\[/', $x) || preg_match('/^\'/', $x)) {
                return "is_array({$x}) ? {$x} : [{$x}]";
            }
            $t = '$__ca' . $this->tmpId++;
            return "is_array({$t} = {$x}) ? {$t} : [{$t}]";
        };

        // Known array type: skip the is_string() check entirely
        if ($objType === TypeHint::Array_) {
            return 'array_merge(' . $obj . ', ' . implode(', ', array_map($wrapArg, $a)) . ')';
        }

        // Known string type: skip the check
        if ($objType === TypeHint::String) {
            return $obj . ' . ' . implode(' . ', $a);
        }

        // Unknown: cache object in temp var to prevent re-evaluation
        $t = '$__t' . $this->tmpId++;
        $strConcat = $t . ' . ' . implode(' . ', $a);
        $arrConcat = 'array_merge(' . $t . ', ' . implode(', ', array_map($wrapArg, $a)) . ')';
        return "(is_string({$t} = {$obj}) ? {$strConcat} : {$arrConcat})";
    }

    private function emitPadStart(string $obj, array $a): string
    {
        $fillStr = $a[1] ?? "' '";
        return "str_pad({$obj}, (int)({$a[0]}), {$fillStr}, STR_PAD_LEFT)";
    }

    private function emitPadEnd(string $obj, array $a): string
    {
        $fillStr = $a[1] ?? "' '";
        return "str_pad({$obj}, (int)({$a[0]}), {$fillStr}, STR_PAD_RIGHT)";
    }

    private function emitLastIndexOf(string $obj, array $a): string
    {
        $t = '$__t' . $this->tmpId++;
        return "(({$t} = mb_strrpos({$obj}, {$a[0]}, 0, 'UTF-8')) === false ? -1 : {$t})";
    }

    // ───────────────── Closures / Functions ─────────────────

    private function emitFunctionExpr(FunctionExpr $expr, ?string $boundName = null, bool $forceBox = false): string
    {
        return $this->emitClosure(
            $expr->name,
            $expr->params,
            $expr->body,
            $expr->isArrow,
            $expr->restParam,
            $forceBox || $this->needsBoxedFunction($boundName ?? $expr->name, $expr->isArrow, $expr->body),
            $expr->defaults,
            $expr->paramDestructures,
        );
    }

    private function emitClosure(
        ?string $name,
        array $params,
        array $body,
        bool $isArrow = false,
        ?string $restParam = null,
        bool $boxFunction = true,
        array $defaults = [],
        array $paramDestructures = [],
    ): string
    {
        $isConstructor = !$isArrow && $this->bodyContainsThis($body);
        $savedRenames = $this->letRenames;
        $savedBlockDepth = $this->blockDepth;
        $this->blockDepth = 0;

        // Push new scope — include rest param and destructured names in scope
        $allParams = $params;
        if ($restParam !== null) {
            $allParams[] = $restParam;
        }
        foreach ($paramDestructures as $pattern) {
            self::collectBindingVarNames($pattern['bindings'], $pattern['restName'], $allParams);
        }
        $this->pushScope($allParams);
        $hoisted = $this->collectVarDecls($body);
        foreach ($hoisted as $v) {
            $this->addVar($v);
        }
        if ($name !== null) {
            $this->addVar($name);
        }

        // Narrowed capture: only capture parent-scope vars actually referenced in body
        $parentVars = $this->getCapturedVars();
        $localNames = array_merge($allParams, $hoisted);
        // NOTE: Don't add $name to localNames — if the body references it
        // for recursion, it must be detected as a free variable and captured.
        $freeInBody = $this->collectReferencedNames($body, $localNames);
        $captured = array_values(array_intersect($freeInBody, $parentVars));
        // Also capture the function's own name for recursion (if referenced)
        if ($name !== null && in_array($name, $freeInBody) && !in_array($name, $captured)) {
            $captured[] = $name;
        }

        $useClause = '';
        if (!empty($captured)) {
            $refs = array_map(fn($v) => '&$' . $this->resolveVar($v), $captured);
            $useClause = ' use (' . implode(', ', $refs) . ')';
        }

        $paramList = [];
        foreach ($params as $i => $p) {
            $hasDefault = isset($defaults[$i]) && $defaults[$i] !== null;
            $paramList[] = '$' . $p . ($hasDefault ? ' = null' : '');
        }
        if ($restParam !== null) {
            $paramList[] = '...$' . $restParam;
        }

        $prevConstructor = $this->inConstructor;
        $this->inConstructor = $isConstructor;

        // Save and restore varTypes around closure body emission
        $savedVarTypes = $this->varTypes;

        $innerCode = '';

        // Default parameter assignments
        foreach ($defaults as $i => $defaultExpr) {
            if ($defaultExpr === null) {
                continue;
            }
            $pName = '$' . $params[$i];
            $innerCode .= "if ({$pName} === null) { {$pName} = " . $this->emitExpr($defaultExpr) . "; }\n";
        }

        // Emit param destructuring: function f({x, y}) → $x = $__p0['x']; $y = $__p0['y'];
        foreach ($paramDestructures as $idx => $pattern) {
            $src = '$' . $params[$idx];
            $innerCode .= $this->emitDestructuringPattern($src, $pattern['isArray'], $pattern['bindings'], $pattern['restName']);
        }

        if ($isConstructor) {
            $innerCode .= '$__this = new ' . self::JS_OBJECT . "(['__scriptlite_ctor' => true]);\n";
        }

        // Hoist function declarations
        foreach ($body as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $innerCode .= $this->emitStmt($stmt);
            }
        }
        foreach ($body as $stmt) {
            if (!($stmt instanceof FunctionDeclaration)) {
                $innerCode .= $this->emitStmt($stmt);
            }
        }

        if ($isConstructor) {
            $innerCode .= "return \$__this;\n";
        }

        $this->varTypes = $savedVarTypes;
        $this->inConstructor = $prevConstructor;
        $this->popScope();
        $this->letRenames = $savedRenames;
        $this->blockDepth = $savedBlockDepth;

        $closure = "static function(" . implode(', ', $paramList) . ")" . $useClause . " {\n"
            . $this->indent($innerCode)
            . "}";

        if ($boxFunction) {
            return 'new ' . self::JS_FUNCTION . '(' . $closure . ')';
        }

        return $closure;
    }

    private function needsBoxedFunction(?string $bindingName, bool $isArrow, array $body): bool
    {
        if (!$isArrow && $this->bodyContainsThis($body)) {
            return true;
        }

        return $bindingName !== null && isset($this->boxedFunctionBindings[$bindingName]);
    }

    private function emitFastEquality(Expr $leftExpr, Expr $rightExpr, bool $strict): ?string
    {
        $left = $this->emitExpr($leftExpr);
        $right = $this->emitExpr($rightExpr);
        $leftType = $this->inferType($leftExpr);
        $rightType = $this->inferType($rightExpr);

        $leftNullish = $leftExpr instanceof NullLiteral || $leftExpr instanceof UndefinedLiteral;
        $rightNullish = $rightExpr instanceof NullLiteral || $rightExpr instanceof UndefinedLiteral;

        if ($leftNullish && $rightNullish) {
            return 'true';
        }

        if ($leftNullish) {
            return '(' . $right . ' === null)';
        }

        if ($rightNullish) {
            return '(' . $left . ' === null)';
        }

        if ($leftType === TypeHint::Numeric && $rightType === TypeHint::Numeric) {
            // When one side is a non-NaN literal, skip its NaN check + cache the other side
            $leftIsLit = $leftExpr instanceof NumberLiteral && !is_nan($leftExpr->value);
            $rightIsLit = $rightExpr instanceof NumberLiteral && !is_nan($rightExpr->value);
            if ($leftIsLit && $rightIsLit) {
                return '(' . $left . ' == ' . $right . ')';
            }
            if ($rightIsLit) {
                [$la, $lr] = $this->inlineGuardVar($left, 'eq');
                return "(!is_nan((float)({$la})) && (float)({$lr}) == (float)({$right}))";
            }
            if ($leftIsLit) {
                [$ra, $rr] = $this->inlineGuardVar($right, 'eq');
                return "(!is_nan((float)({$ra})) && (float)({$left}) == (float)({$rr}))";
            }
            // Both non-literal: cache both to avoid double evaluation
            [$la, $lr] = $this->inlineGuardVar($left, 'eq');
            [$ra, $rr] = $this->inlineGuardVar($right, 'eq');
            return "(!is_nan((float)({$la})) && !is_nan((float)({$ra})) && (float)({$lr}) == (float)({$rr}))";
        }

        if ($leftType === TypeHint::String && $rightType === TypeHint::String) {
            return '(' . $left . ' === ' . $right . ')';
        }

        if ($leftType === TypeHint::Bool && $rightType === TypeHint::Bool) {
            return '(' . $left . ' === ' . $right . ')';
        }

        if (!$strict && $leftType === $rightType && $leftType !== TypeHint::Unknown) {
            return match ($leftType) {
                TypeHint::Numeric => '(!is_nan((float)(' . $left . ')) && !is_nan((float)(' . $right . ')) && ((float)(' . $left . ') == (float)(' . $right . ')))',
                TypeHint::String, TypeHint::Bool => '(' . $left . ' === ' . $right . ')',
                default => null,
            };
        }

        return null;
    }

    // ───────────────── Scope Management ─────────────────

    private function pushScope(array $params): void
    {
        $vars = [];
        foreach ($params as $p) {
            $vars[$p] = true;
        }
        $this->scopes[] = $vars;
    }

    private function popScope(): void
    {
        array_pop($this->scopes);
    }

    private function addVar(string $name): void
    {
        if (!empty($this->scopes)) {
            $this->scopes[count($this->scopes) - 1][$name] = true;
        }
    }

    /** Get all variable names from parent scopes (not current) for use() clause */
    private function getCapturedVars(): array
    {
        $vars = [];
        for ($i = 0; $i < count($this->scopes) - 1; $i++) {
            foreach ($this->scopes[$i] as $name => $_) {
                $vars[$name] = true;
            }
        }
        return array_keys($vars);
    }

    /** Collect all var-declared names in a statement list (hoisted, non-recursive into functions) */
    private function collectVarDecls(array $stmts): array
    {
        $vars = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof VarDeclaration) {
                $vars[] = $stmt->name;
            } elseif ($stmt instanceof FunctionDeclaration) {
                $vars[] = $stmt->name;
            } elseif ($stmt instanceof ForStmt && $stmt->init instanceof VarDeclaration) {
                $vars[] = $stmt->init->name;
            } elseif ($stmt instanceof IfStmt) {
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->consequent]));
                if ($stmt->alternate !== null) {
                    $vars = array_merge($vars, $this->collectVarDecls([$stmt->alternate]));
                }
            } elseif ($stmt instanceof ForOfStmt) {
                $vars[] = $stmt->name;
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->body]));
            } elseif ($stmt instanceof ForInStmt) {
                $vars[] = $stmt->name;
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->body]));
            } elseif ($stmt instanceof DestructuringDeclaration) {
                self::collectBindingVarNames($stmt->bindings, $stmt->restName, $vars);
            } elseif ($stmt instanceof WhileStmt) {
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->body]));
            } elseif ($stmt instanceof DoWhileStmt) {
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->body]));
            } elseif ($stmt instanceof BlockStmt) {
                $vars = array_merge($vars, $this->collectVarDecls($stmt->statements));
            } elseif ($stmt instanceof SwitchStmt) {
                foreach ($stmt->cases as $case) {
                    $vars = array_merge($vars, $this->collectVarDecls($case->consequent));
                }
            } elseif ($stmt instanceof TryCatchStmt) {
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->block]));
                if ($stmt->handler !== null) {
                    $vars = array_merge($vars, $this->collectVarDecls([$stmt->handler->body]));
                }
            }
        }
        return array_unique($vars);
    }

    private static function collectBindingVarNames(array $bindings, ?string $restName, array &$vars): void
    {
        foreach ($bindings as $b) {
            if ($b['name'] === null && isset($b['nested'])) {
                self::collectBindingVarNames($b['nested']['bindings'], $b['nested']['restName'], $vars);
            } else {
                $vars[] = $b['name'];
            }
        }
        if ($restName !== null) {
            $vars[] = $restName;
        }
    }

    // ───────────────── Function Boxing Analysis ─────────────────

    private function collectBoxedFunctionBindings(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->walkStmtForBoxedFunctions($stmt);
        }
        $this->propagateBoxedFunctionAliases();
    }

    private function markFunctionBinding(string $name): void
    {
        $this->boxedFunctionBindings[$name] = true;
    }

    private function addFunctionBindingAlias(string $target, string $source): void
    {
        $this->functionBindingAliases[$target][$source] = true;
    }

    private function walkStmtForBoxedFunctions(Stmt $stmt): void
    {
        if ($stmt instanceof ExpressionStmt) {
            $this->walkExprForBoxedFunctions($stmt->expression);
            return;
        }

        if ($stmt instanceof VarDeclaration) {
            if ($stmt->initializer !== null) {
                if ($stmt->initializer instanceof Identifier) {
                    $this->addFunctionBindingAlias($stmt->name, $stmt->initializer->name);
                }
                $this->walkExprForBoxedFunctions($stmt->initializer);
            }
            return;
        }

        if ($stmt instanceof ReturnStmt) {
            if ($stmt->value !== null) {
                $this->walkExprForBoxedFunctions($stmt->value);
            }
            return;
        }

        if ($stmt instanceof IfStmt) {
            $this->walkExprForBoxedFunctions($stmt->condition);
            $this->walkStmtForBoxedFunctions($stmt->consequent);
            if ($stmt->alternate !== null) {
                $this->walkStmtForBoxedFunctions($stmt->alternate);
            }
            return;
        }

        if ($stmt instanceof WhileStmt) {
            $this->walkExprForBoxedFunctions($stmt->condition);
            $this->walkStmtForBoxedFunctions($stmt->body);
            return;
        }

        if ($stmt instanceof ForStmt) {
            if ($stmt->init instanceof VarDeclaration && $stmt->init->initializer !== null) {
                $this->walkExprForBoxedFunctions($stmt->init->initializer);
            } elseif ($stmt->init instanceof ExpressionStmt) {
                $this->walkExprForBoxedFunctions($stmt->init->expression);
            }
            if ($stmt->condition !== null) {
                $this->walkExprForBoxedFunctions($stmt->condition);
            }
            if ($stmt->update !== null) {
                $this->walkExprForBoxedFunctions($stmt->update);
            }
            $this->walkStmtForBoxedFunctions($stmt->body);
            return;
        }

        if ($stmt instanceof ForOfStmt) {
            $this->walkExprForBoxedFunctions($stmt->iterable);
            $this->walkStmtForBoxedFunctions($stmt->body);
            return;
        }

        if ($stmt instanceof ForInStmt) {
            $this->walkExprForBoxedFunctions($stmt->object);
            $this->walkStmtForBoxedFunctions($stmt->body);
            return;
        }

        if ($stmt instanceof DestructuringDeclaration) {
            $this->walkExprForBoxedFunctions($stmt->initializer);
            foreach ($stmt->bindings as $binding) {
                if (($binding['default'] ?? null) instanceof Expr) {
                    $this->walkExprForBoxedFunctions($binding['default']);
                }
            }
            return;
        }

        if ($stmt instanceof DoWhileStmt) {
            $this->walkStmtForBoxedFunctions($stmt->body);
            $this->walkExprForBoxedFunctions($stmt->condition);
            return;
        }

        if ($stmt instanceof BlockStmt) {
            foreach ($stmt->statements as $nested) {
                $this->walkStmtForBoxedFunctions($nested);
            }
            return;
        }

        if ($stmt instanceof SwitchStmt) {
            $this->walkExprForBoxedFunctions($stmt->discriminant);
            foreach ($stmt->cases as $case) {
                if ($case->test !== null) {
                    $this->walkExprForBoxedFunctions($case->test);
                }
                foreach ($case->consequent as $nested) {
                    $this->walkStmtForBoxedFunctions($nested);
                }
            }
            return;
        }

        if ($stmt instanceof ThrowStmt) {
            $this->walkExprForBoxedFunctions($stmt->argument);
            return;
        }

        if ($stmt instanceof TryCatchStmt) {
            foreach ($stmt->block->statements as $nested) {
                $this->walkStmtForBoxedFunctions($nested);
            }
            if ($stmt->handler !== null) {
                foreach ($stmt->handler->body->statements as $nested) {
                    $this->walkStmtForBoxedFunctions($nested);
                }
            }
            return;
        }

        if ($stmt instanceof FunctionDeclaration) {
            foreach ($stmt->body as $nested) {
                $this->walkStmtForBoxedFunctions($nested);
            }
        }
    }

    private function walkExprForBoxedFunctions(Expr $expr): void
    {
        if ($expr instanceof BinaryExpr) {
            $this->walkExprForBoxedFunctions($expr->left);
            $this->walkExprForBoxedFunctions($expr->right);
            if ($expr->operator === 'instanceof' && $expr->right instanceof Identifier) {
                $this->markFunctionBinding($expr->right->name);
            }
            return;
        }

        if ($expr instanceof LogicalExpr) {
            $this->walkExprForBoxedFunctions($expr->left);
            $this->walkExprForBoxedFunctions($expr->right);
            return;
        }

        if ($expr instanceof AssignExpr) {
            if ($expr->operator === '=' && $expr->value instanceof Identifier) {
                $this->addFunctionBindingAlias($expr->name, $expr->value->name);
            }
            $this->walkExprForBoxedFunctions($expr->value);
            return;
        }

        if ($expr instanceof UnaryExpr) {
            $this->walkExprForBoxedFunctions($expr->operand);
            return;
        }

        if ($expr instanceof TypeofExpr) {
            $this->walkExprForBoxedFunctions($expr->operand);
            return;
        }

        if ($expr instanceof VoidExpr) {
            $this->walkExprForBoxedFunctions($expr->operand);
            return;
        }

        if ($expr instanceof DeleteExpr) {
            $this->walkExprForBoxedFunctions($expr->operand);
            return;
        }

        if ($expr instanceof UpdateExpr) {
            $this->walkExprForBoxedFunctions($expr->argument);
            return;
        }

        if ($expr instanceof CallExpr) {
            $this->walkExprForBoxedFunctions($expr->callee);
            foreach ($expr->arguments as $arg) {
                $this->walkExprForBoxedFunctions($arg instanceof SpreadElement ? $arg->argument : $arg);
            }
            return;
        }

        if ($expr instanceof NewExpr) {
            if ($expr->callee instanceof Identifier) {
                $this->markFunctionBinding($expr->callee->name);
            } else {
                $this->walkExprForBoxedFunctions($expr->callee);
            }
            foreach ($expr->arguments as $arg) {
                $this->walkExprForBoxedFunctions($arg instanceof SpreadElement ? $arg->argument : $arg);
            }
            return;
        }

        if ($expr instanceof MemberExpr) {
            if ($expr->object instanceof Identifier) {
                $this->markFunctionBinding($expr->object->name);
            } else {
                $this->walkExprForBoxedFunctions($expr->object);
            }
            if ($expr->computed) {
                $this->walkExprForBoxedFunctions($expr->property);
            }
            return;
        }

        if ($expr instanceof MemberAssignExpr) {
            if ($expr->object instanceof Identifier) {
                $this->markFunctionBinding($expr->object->name);
            } else {
                $this->walkExprForBoxedFunctions($expr->object);
            }
            if ($expr->computed) {
                $this->walkExprForBoxedFunctions($expr->property);
            }
            $this->walkExprForBoxedFunctions($expr->value);
            return;
        }

        if ($expr instanceof ConditionalExpr) {
            $this->walkExprForBoxedFunctions($expr->condition);
            $this->walkExprForBoxedFunctions($expr->consequent);
            $this->walkExprForBoxedFunctions($expr->alternate);
            return;
        }

        if ($expr instanceof ArrayLiteral) {
            foreach ($expr->elements as $element) {
                $this->walkExprForBoxedFunctions($element instanceof SpreadElement ? $element->argument : $element);
            }
            return;
        }

        if ($expr instanceof ObjectLiteral) {
            foreach ($expr->properties as $property) {
                if ($property->computed && $property->computedKey !== null) {
                    $this->walkExprForBoxedFunctions($property->computedKey);
                }
                $this->walkExprForBoxedFunctions($property->value);
            }
            return;
        }

        if ($expr instanceof TemplateLiteral) {
            foreach ($expr->expressions as $embedded) {
                $this->walkExprForBoxedFunctions($embedded);
            }
            return;
        }

        if ($expr instanceof FunctionExpr) {
            foreach ($expr->body as $stmt) {
                $this->walkStmtForBoxedFunctions($stmt);
            }
        }
    }

    private function propagateBoxedFunctionAliases(): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->functionBindingAliases as $target => $sources) {
                if (!isset($this->boxedFunctionBindings[$target])) {
                    continue;
                }
                foreach ($sources as $source => $_) {
                    if (!isset($this->boxedFunctionBindings[$source])) {
                        $this->boxedFunctionBindings[$source] = true;
                        $changed = true;
                    }
                }
            }
        }
    }

    // ───────────────── Helpers ─────────────────

    private function bodyContainsThis(array $body): bool
    {
        foreach ($body as $stmt) {
            if ($this->nodeContainsThis($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsThis(mixed $node): bool
    {
        if ($node instanceof ThisExpr) return true;
        if ($node instanceof ExpressionStmt) return $this->nodeContainsThis($node->expression);
        if ($node instanceof MemberAssignExpr) return $node->object instanceof ThisExpr;
        if ($node instanceof MemberExpr) return $node->object instanceof ThisExpr;
        if ($node instanceof BlockStmt) {
            foreach ($node->statements as $s) {
                if ($this->nodeContainsThis($s)) return true;
            }
        }
        if ($node instanceof IfStmt) {
            return $this->nodeContainsThis($node->consequent)
                || ($node->alternate !== null && $this->nodeContainsThis($node->alternate));
        }
        return false;
    }

    /** Get all variable names from all scopes (for IIFE use clauses) */
    private function getAllScopeVars(): array
    {
        $vars = [];
        foreach ($this->scopes as $scope) {
            foreach ($scope as $name => $_) {
                $vars[$name] = true;
            }
        }
        return array_keys($vars);
    }

    /** Generate a use() clause capturing all scope variables by reference */
    private function makeUseClause(): string
    {
        return $this->makeUseClauseWith([]);
    }

    /** Convert JS regex pattern+flags to a PCRE pattern string (PHP literal) */
    private function jsToPcre(string $pattern, string $flags): string
    {
        $pcreFlags = str_replace('g', '', $flags);
        $escaped = addcslashes($pattern, "'\\");
        return "'/{$escaped}/{$pcreFlags}u'";
    }

    private function escapeStr(string $s): string
    {
        // Check if string contains any control characters that need double-quoted PHP string
        if (preg_match('/[\x00-\x1F\x7F]/', $s)) {
            $escaped = str_replace(
                ["\\", "\$", "\"", "\n", "\r", "\t", "\0", "\x08", "\f", "\x0B"],
                ["\\\\", "\\\$", "\\\"", "\\n", "\\r", "\\t", "\\0", "\\x08", "\\f", "\\v"],
                $s,
            );
            return '"' . $escaped . '"';
        }
        return "'" . addcslashes($s, "'\\") . "'";
    }

    private function indent(string $code): string
    {
        $lines = explode("\n", rtrim($code, "\n"));
        return implode("\n", array_map(fn($l) => $l === '' ? '' : '    ' . $l, $lines)) . "\n";
    }

    // ───────────────── Type Inference ─────────────────

    /**
     * Merge two branch type snapshots: keep a type only if both branches agree.
     * Variables that differ between branches become Unknown (removed from map).
     */
    private function mergeBranchTypes(array $before, array $branch): array
    {
        $result = [];
        foreach ($before as $name => $type) {
            if (isset($branch[$name]) && $branch[$name] === $type) {
                $result[$name] = $type;
            }
        }
        // Also keep types introduced in branch if not conflicting
        foreach ($branch as $name => $type) {
            if (!isset($before[$name]) && !isset($result[$name])) {
                // New variable introduced in branch — keep it
                $result[$name] = $type;
            }
        }
        return $result;
    }

    private function trackVar(string $name, TypeHint $type): void
    {
        if ($type !== TypeHint::Unknown) {
            $this->varTypes[$name] = $type;
        } else {
            unset($this->varTypes[$name]);
        }
    }

    private function inferFromName(string $name): TypeHint
    {
        return $this->varTypes[$name] ?? TypeHint::Unknown;
    }

    private function inferType(Expr $e): TypeHint
    {
        return match (true) {
            $e instanceof NumberLiteral => TypeHint::Numeric,
            $e instanceof StringLiteral,
            $e instanceof TemplateLiteral => TypeHint::String,
            $e instanceof BooleanLiteral => TypeHint::Bool,
            $e instanceof ArrayLiteral => TypeHint::Array_,
            $e instanceof ObjectLiteral => TypeHint::Object_,

            // Arithmetic always produces numeric
            $e instanceof BinaryExpr && in_array($e->operator, ['-', '*', '/', '%', '**', '&', '|', '^', '<<', '>>', '>>>'], true)
                => TypeHint::Numeric,

            // + with two known-numerics → numeric
            $e instanceof BinaryExpr && $e->operator === '+'
                && $this->inferType($e->left) === TypeHint::Numeric
                && $this->inferType($e->right) === TypeHint::Numeric
                => TypeHint::Numeric,

            // + with a known string → string
            $e instanceof BinaryExpr && $e->operator === '+'
                && ($this->inferType($e->left) === TypeHint::String || $this->inferType($e->right) === TypeHint::String)
                => TypeHint::String,

            // Comparison operators → bool
            $e instanceof BinaryExpr && in_array($e->operator, ['==', '!=', '===', '!==', '<', '<=', '>', '>=', 'in', 'instanceof'], true)
                => TypeHint::Bool,

            // Unary operators
            $e instanceof UnaryExpr && $e->operator === '-' => TypeHint::Numeric,
            $e instanceof UnaryExpr && $e->operator === '~' => TypeHint::Numeric,
            $e instanceof UnaryExpr && $e->operator === '!' => TypeHint::Bool,

            // Update (++/--) → numeric
            $e instanceof UpdateExpr => TypeHint::Numeric,

            // Assignment inherits type from value
            $e instanceof AssignExpr && $e->operator === '=' => $this->inferType($e->value),

            // Compound numeric assignments
            $e instanceof AssignExpr && in_array($e->operator, ['-=', '*=', '/=', '%=', '**=', '&=', '|=', '^=', '<<=', '>>=', '>>>='], true)
                => TypeHint::Numeric,

            // += where both sides are numeric → numeric
            $e instanceof AssignExpr && $e->operator === '+='
                && $this->inferFromName($e->name) === TypeHint::Numeric
                && $this->inferType($e->value) === TypeHint::Numeric
                => TypeHint::Numeric,

            // Method calls with known return types
            $e instanceof CallExpr && $e->callee instanceof MemberExpr
                && !$e->callee->computed
                && $e->callee->property instanceof Identifier
                => $this->inferMethodReturnType($e->callee),

            // .length → always numeric
            $e instanceof MemberExpr && !$e->computed
                && $e->property instanceof Identifier && $e->property->name === 'length'
                => TypeHint::Numeric,

            // Identifiers: check tracked type
            $e instanceof Identifier => $this->inferFromName($e->name),

            // Logical operators inherit from their branches
            $e instanceof LogicalExpr && $e->operator === '||' => $this->inferLogicalType($e),
            $e instanceof LogicalExpr && $e->operator === '&&' => $this->inferLogicalType($e),

            // Conditional: if both branches agree, use that type
            $e instanceof ConditionalExpr => $this->inferConditionalType($e),

            default => TypeHint::Unknown,
        };
    }

    private function inferMethodReturnType(MemberExpr $callee): TypeHint
    {
        $method = $callee->property->name;

        // Math.* → numeric
        if ($callee->object instanceof Identifier && $callee->object->name === 'Math') {
            return TypeHint::Numeric;
        }

        return match ($method) {
            // Array methods returning arrays
            'filter', 'map', 'concat', 'reverse', 'flat', 'splice', 'sort', 'slice'
                => TypeHint::Array_,
            // Methods returning numeric
            'indexOf', 'findIndex', 'search', 'push', 'unshift'
                => TypeHint::Numeric,
            // Methods returning string
            'join', 'toUpperCase', 'toLowerCase', 'trim', 'trimStart', 'trimEnd',
            'charAt', 'substring', 'repeat', 'replace'
                => TypeHint::String,
            // Methods returning bool
            'includes', 'startsWith', 'endsWith', 'every', 'some', 'hasOwnProperty'
                => TypeHint::Bool,
            default => TypeHint::Unknown,
        };
    }

    private function inferLogicalType(LogicalExpr $e): TypeHint
    {
        $left = $this->inferType($e->left);
        $right = $this->inferType($e->right);
        return ($left === $right) ? $left : TypeHint::Unknown;
    }

    private function inferConditionalType(ConditionalExpr $e): TypeHint
    {
        $cons = $this->inferType($e->consequent);
        $alt = $this->inferType($e->alternate);
        return ($cons === $alt) ? $cons : TypeHint::Unknown;
    }

    // ───────────────── Free Variable Collection ─────────────────

    /**
     * Collect all Identifier names referenced in a function body,
     * excluding names declared locally (params, var/function decls).
     * Does NOT descend into nested function bodies.
     */
    private function collectReferencedNames(array $body, array $localNames): array
    {
        $referenced = [];
        $declared = array_flip($localNames);
        foreach ($body as $stmt) {
            $this->walkStmtForNames($stmt, $referenced, $declared);
        }
        return array_keys($referenced);
    }

    private function walkStmtForNames(Stmt $s, array &$referenced, array &$declared): void
    {
        if ($s instanceof ExpressionStmt) {
            $this->walkExprForNames($s->expression, $referenced, $declared);
        } elseif ($s instanceof VarDeclaration) {
            $declared[$s->name] = true;
            if ($s->initializer !== null) {
                $this->walkExprForNames($s->initializer, $referenced, $declared);
            }
        } elseif ($s instanceof ReturnStmt) {
            if ($s->value !== null) {
                $this->walkExprForNames($s->value, $referenced, $declared);
            }
        } elseif ($s instanceof IfStmt) {
            $this->walkExprForNames($s->condition, $referenced, $declared);
            $this->walkStmtForNames($s->consequent, $referenced, $declared);
            if ($s->alternate !== null) {
                $this->walkStmtForNames($s->alternate, $referenced, $declared);
            }
        } elseif ($s instanceof WhileStmt) {
            $this->walkExprForNames($s->condition, $referenced, $declared);
            $this->walkStmtForNames($s->body, $referenced, $declared);
        } elseif ($s instanceof ForStmt) {
            if ($s->init instanceof VarDeclaration) {
                $declared[$s->init->name] = true;
                if ($s->init->initializer !== null) {
                    $this->walkExprForNames($s->init->initializer, $referenced, $declared);
                }
            } elseif ($s->init instanceof ExpressionStmt) {
                $this->walkExprForNames($s->init->expression, $referenced, $declared);
            }
            if ($s->condition !== null) {
                $this->walkExprForNames($s->condition, $referenced, $declared);
            }
            if ($s->update !== null) {
                $this->walkExprForNames($s->update, $referenced, $declared);
            }
            $this->walkStmtForNames($s->body, $referenced, $declared);
        } elseif ($s instanceof ForOfStmt) {
            $declared[$s->name] = true;
            $this->walkExprForNames($s->iterable, $referenced, $declared);
            $this->walkStmtForNames($s->body, $referenced, $declared);
        } elseif ($s instanceof ForInStmt) {
            $declared[$s->name] = true;
            $this->walkExprForNames($s->object, $referenced, $declared);
            $this->walkStmtForNames($s->body, $referenced, $declared);
        } elseif ($s instanceof DestructuringDeclaration) {
            foreach ($s->bindings as $b) {
                $declared[$b['name']] = true;
            }
            if ($s->restName !== null) {
                $declared[$s->restName] = true;
            }
            $this->walkExprForNames($s->initializer, $referenced, $declared);
        } elseif ($s instanceof DoWhileStmt) {
            $this->walkStmtForNames($s->body, $referenced, $declared);
            $this->walkExprForNames($s->condition, $referenced, $declared);
        } elseif ($s instanceof BlockStmt) {
            foreach ($s->statements as $st) {
                $this->walkStmtForNames($st, $referenced, $declared);
            }
        } elseif ($s instanceof SwitchStmt) {
            $this->walkExprForNames($s->discriminant, $referenced, $declared);
            foreach ($s->cases as $c) {
                if ($c->test !== null) {
                    $this->walkExprForNames($c->test, $referenced, $declared);
                }
                foreach ($c->consequent as $st) {
                    $this->walkStmtForNames($st, $referenced, $declared);
                }
            }
        } elseif ($s instanceof ThrowStmt) {
            $this->walkExprForNames($s->argument, $referenced, $declared);
        } elseif ($s instanceof TryCatchStmt) {
            foreach ($s->block->statements as $st) {
                $this->walkStmtForNames($st, $referenced, $declared);
            }
            if ($s->handler !== null) {
                $declared[$s->handler->param] = true;
                foreach ($s->handler->body->statements as $st) {
                    $this->walkStmtForNames($st, $referenced, $declared);
                }
            }
        } elseif ($s instanceof FunctionDeclaration) {
            $declared[$s->name] = true;
            // Descend into body to find free variables that must propagate
            $innerDeclared = $declared;
            foreach ($s->params as $p) {
                $innerDeclared[$p] = true;
            }
            if ($s->restParam !== null) {
                $innerDeclared[$s->restParam] = true;
            }
            foreach ($this->collectVarDecls($s->body) as $v) {
                $innerDeclared[$v] = true;
            }
            foreach ($s->body as $st) {
                $this->walkStmtForNames($st, $referenced, $innerDeclared);
            }
        }
    }

    private function walkExprForNames(Expr $e, array &$referenced, array &$declared): void
    {
        if ($e instanceof Identifier) {
            if (!isset($declared[$e->name])) {
                $referenced[$e->name] = true;
            }
        } elseif ($e instanceof FunctionExpr) {
            // Descend into nested function bodies to find free variables
            // that must propagate through this scope. Treat the inner
            // function's params + var-decls as its own locals.
            $innerDeclared = $declared;
            foreach ($e->params as $p) {
                $innerDeclared[$p] = true;
            }
            if ($e->restParam !== null) {
                $innerDeclared[$e->restParam] = true;
            }
            if ($e->name !== null) {
                $innerDeclared[$e->name] = true;
            }
            foreach ($this->collectVarDecls($e->body) as $v) {
                $innerDeclared[$v] = true;
            }
            foreach ($e->body as $st) {
                $this->walkStmtForNames($st, $referenced, $innerDeclared);
            }
        } elseif ($e instanceof BinaryExpr || $e instanceof LogicalExpr) {
            $this->walkExprForNames($e->left, $referenced, $declared);
            $this->walkExprForNames($e->right, $referenced, $declared);
        } elseif ($e instanceof AssignExpr) {
            if (!isset($declared[$e->name])) {
                $referenced[$e->name] = true;
            }
            $this->walkExprForNames($e->value, $referenced, $declared);
        } elseif ($e instanceof UnaryExpr) {
            $this->walkExprForNames($e->operand, $referenced, $declared);
        } elseif ($e instanceof TypeofExpr) {
            $this->walkExprForNames($e->operand, $referenced, $declared);
        } elseif ($e instanceof VoidExpr) {
            $this->walkExprForNames($e->operand, $referenced, $declared);
        } elseif ($e instanceof DeleteExpr) {
            $this->walkExprForNames($e->operand, $referenced, $declared);
        } elseif ($e instanceof UpdateExpr) {
            $this->walkExprForNames($e->argument, $referenced, $declared);
        } elseif ($e instanceof CallExpr) {
            $this->walkExprForNames($e->callee, $referenced, $declared);
            foreach ($e->arguments as $a) {
                $this->walkExprForNames($a instanceof SpreadElement ? $a->argument : $a, $referenced, $declared);
            }
        } elseif ($e instanceof NewExpr) {
            $this->walkExprForNames($e->callee, $referenced, $declared);
            foreach ($e->arguments as $a) {
                $this->walkExprForNames($a instanceof SpreadElement ? $a->argument : $a, $referenced, $declared);
            }
        } elseif ($e instanceof MemberExpr) {
            $this->walkExprForNames($e->object, $referenced, $declared);
            if ($e->computed) {
                $this->walkExprForNames($e->property, $referenced, $declared);
            }
        } elseif ($e instanceof MemberAssignExpr) {
            $this->walkExprForNames($e->object, $referenced, $declared);
            if ($e->computed) {
                $this->walkExprForNames($e->property, $referenced, $declared);
            }
            $this->walkExprForNames($e->value, $referenced, $declared);
        } elseif ($e instanceof ConditionalExpr) {
            $this->walkExprForNames($e->condition, $referenced, $declared);
            $this->walkExprForNames($e->consequent, $referenced, $declared);
            $this->walkExprForNames($e->alternate, $referenced, $declared);
        } elseif ($e instanceof ArrayLiteral) {
            foreach ($e->elements as $el) {
                $this->walkExprForNames($el instanceof SpreadElement ? $el->argument : $el, $referenced, $declared);
            }
        } elseif ($e instanceof ObjectLiteral) {
            foreach ($e->properties as $p) {
                $this->walkExprForNames($p->value, $referenced, $declared);
            }
        } elseif ($e instanceof TemplateLiteral) {
            foreach ($e->expressions as $ex) {
                $this->walkExprForNames($ex, $referenced, $declared);
            }
        }
        // Literals (Number, String, Boolean, Null, Undefined, Regex, This) — no references
    }
}
