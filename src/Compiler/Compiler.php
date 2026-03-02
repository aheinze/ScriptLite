<?php

declare(strict_types=1);

namespace ScriptLite\Compiler;

use ScriptLite\Ast\{
    ArrayLiteral, AssignExpr, BinaryExpr, BlockStmt, BooleanLiteral, BreakStmt, CallExpr,
    ConditionalExpr, ContinueStmt, DestructuringDeclaration, DeleteExpr, DoWhileStmt,
    ExpressionStmt, Expr, ForInStmt, ForOfStmt, ForStmt,
    FunctionDeclaration, FunctionExpr,
    Identifier, IfStmt, LogicalExpr, MemberAssignExpr, MemberExpr, NewExpr, NullLiteral, NumberLiteral,
    ObjectLiteral, Program, RegexLiteral, ReturnStmt, SpreadElement, Stmt, StringLiteral, SwitchStmt,
    TemplateLiteral, ThisExpr,
    ThrowStmt, TryCatchStmt, TypeofExpr, UnaryExpr, UndefinedLiteral, UpdateExpr, VoidExpr,
    VarDeclaration, VarKind, WhileStmt
};
use ScriptLite\Runtime\JsRegex;
use ScriptLite\Runtime\JsUndefined;
use RuntimeException;

/**
 * Single-pass AST → linear bytecode compiler.
 *
 * Architecture:
 * - Maintains a constant pool (deduplicated) and a name pool per compilation unit.
 * - Emits Instruction objects into a flat array.
 * - Jump targets are patched in-place (backpatching) for if/while/for.
 * - Functions compile recursively into FunctionDescriptor objects stored in the constant pool.
 *
 * Register allocation:
 * - Non-captured local variables (params + var declarations) are assigned integer register slots.
 * - Variables captured by inner functions stay in the Environment (scope chain).
 * - Register access (GetReg/SetReg) is ~13x faster than Environment access (GetLocal/SetLocal).
 */
final class Compiler
{
    /** @var OpCode[] Flat opcode array */
    private array $ops = [];
    /** @var int[] Flat operandA array */
    private array $opA = [];
    /** @var int[] Flat operandB array */
    private array $opB = [];

    /** @var array<int|float|string|bool|null|FunctionDescriptor> */
    private array $constants = [];

    /** @var array<string, int> Dedup map for constants */
    private array $constMap = [];

    /** @var string[] Variable name pool */
    private array $names = [];

    /** @var array<string, int> Dedup map for names */
    private array $nameMap = [];

    // ── Register allocation state ──

    /** @var array<string, int> Variable name → register slot index */
    private array $regMap = [];

    /** @var int Number of register slots allocated */
    private int $regCount = 0;

    /** @var int Counter for unique temp variable names */
    private int $tempCounter = 0;

    // ── Loop/switch context for break/continue ──

    /** @var array<array{continuePatches: int[], breakPatches: int[]}> */
    private array $loopStack = [];

    public function compile(Program $program): CompiledScript
    {
        // Analyze locals for register allocation at the top-level scope
        $this->analyzeLocals([], $program->body);

        // Hoist function declarations first
        foreach ($program->body as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $this->compileFunctionDeclarationHoist($stmt);
            }
        }

        // Filter non-hoisted statements
        $stmts = [];
        foreach ($program->body as $stmt) {
            if (!($stmt instanceof FunctionDeclaration)) {
                $stmts[] = $stmt;
            }
        }

        $lastIdx = count($stmts) - 1;
        foreach ($stmts as $i => $stmt) {
            // For the last statement: if it's an ExpressionStmt, compile it
            // without Pop so its value stays on the stack as the program result.
            if ($i === $lastIdx && $stmt instanceof ExpressionStmt) {
                $this->compileExpr($stmt->expression);
            } else {
                $this->compileStmt($stmt);
            }
        }

        $this->emit(OpCode::Halt);

        return new CompiledScript(
            new FunctionDescriptor(
                name: '<main>',
                params: [],
                ops: $this->ops,
                opA: $this->opA,
                opB: $this->opB,
                constants: $this->constants,
                names: $this->names,
                regCount: $this->regCount,
                paramSlots: [],
            )
        );
    }

    // ──────────────────── Statement compilation ────────────────────

    private function compileStmt(Stmt $stmt): void
    {
        match (true) {
            $stmt instanceof ExpressionStmt => $this->compileExpressionStmt($stmt),
            $stmt instanceof VarDeclaration => $this->compileVarDeclaration($stmt),
            $stmt instanceof FunctionDeclaration => $this->compileFunctionDeclarationHoist($stmt),
            $stmt instanceof ReturnStmt => $this->compileReturn($stmt),
            $stmt instanceof BlockStmt => $this->compileBlock($stmt),
            $stmt instanceof IfStmt => $this->compileIf($stmt),
            $stmt instanceof WhileStmt => $this->compileWhile($stmt),
            $stmt instanceof ForStmt => $this->compileFor($stmt),
            $stmt instanceof ForOfStmt => $this->compileForOf($stmt),
            $stmt instanceof ForInStmt => $this->compileForIn($stmt),
            $stmt instanceof DestructuringDeclaration => $this->compileDestructuring($stmt),
            $stmt instanceof BreakStmt => $this->compileBreak(),
            $stmt instanceof ContinueStmt => $this->compileContinue(),
            $stmt instanceof DoWhileStmt => $this->compileDoWhile($stmt),
            $stmt instanceof SwitchStmt => $this->compileSwitch($stmt),
            $stmt instanceof ThrowStmt => $this->compileThrow($stmt),
            $stmt instanceof TryCatchStmt => $this->compileTryCatch($stmt),
            default => throw new RuntimeException('Unknown statement type: ' . get_class($stmt)),
        };
    }

    private function compileExpressionStmt(ExpressionStmt $stmt): void
    {
        $this->compileExpr($stmt->expression);
        $this->emit(OpCode::Pop); // discard expression result
    }

    private function compileVarDeclaration(VarDeclaration $decl): void
    {
        if ($decl->initializer !== null) {
            $this->compileExpr($decl->initializer);
        } else {
            $this->emit(OpCode::Const, $this->addConstant(JsUndefined::Value));
        }

        if (isset($this->regMap[$decl->name])) {
            $this->emit(OpCode::SetReg, $this->regMap[$decl->name]);
        } else {
            $nameIdx = $this->addName($decl->name);
            $kindVal = match ($decl->kind) {
                VarKind::Var => 0,
                VarKind::Let => 1,
                VarKind::Const => 2,
            };
            $this->emit(OpCode::DefineVar, $nameIdx, $kindVal);
        }
    }

    private function compileFunctionDeclarationHoist(FunctionDeclaration $decl): void
    {
        $descriptor = $this->compileFunction($decl->name, $decl->params, $decl->body, $decl->restParam, $decl->defaults);
        $descIdx    = $this->addConstant($descriptor);

        $this->emit(OpCode::MakeClosure, $descIdx);

        if (isset($this->regMap[$decl->name])) {
            $this->emit(OpCode::SetReg, $this->regMap[$decl->name]);
        } else {
            $nameIdx = $this->addName($decl->name);
            $this->emit(OpCode::DefineVar, $nameIdx, 0); // var-like hoisting
        }
    }

    private function compileReturn(ReturnStmt $stmt): void
    {
        if ($stmt->value !== null) {
            $this->compileExpr($stmt->value);
        } else {
            $this->emit(OpCode::Const, $this->addConstant(JsUndefined::Value));
        }
        $this->emit(OpCode::Return);
    }

    private function compileBlock(BlockStmt $block): void
    {
        $needsScope = $this->blockNeedsScope($block->statements);

        if ($needsScope) {
            $this->emit(OpCode::PushScope);
        }

        foreach ($block->statements as $stmt) {
            $this->compileStmt($stmt);
        }

        if ($needsScope) {
            $this->emit(OpCode::PopScope);
        }
    }

    /**
     * A block needs a scope only if it has let/const that are NOT register-allocated.
     * @param Stmt[] $stmts
     */
    private function blockNeedsScope(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof VarDeclaration && $stmt->kind !== VarKind::Var) {
                if (!isset($this->regMap[$stmt->name])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function compileIf(IfStmt $stmt): void
    {
        $this->compileExpr($stmt->condition);
        $jumpIfFalse = $this->emitJump(OpCode::JumpIfFalse);

        $this->compileStmt($stmt->consequent);

        if ($stmt->alternate !== null) {
            $jumpOver = $this->emitJump(OpCode::Jump);
            $this->patchJump($jumpIfFalse);
            $this->compileStmt($stmt->alternate);
            $this->patchJump($jumpOver);
        } else {
            $this->patchJump($jumpIfFalse);
        }
    }

    private function compileWhile(WhileStmt $stmt): void
    {
        $loopStart = count($this->ops);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        $this->compileExpr($stmt->condition);
        $exitJump = $this->emitJump(OpCode::JumpIfFalse);

        $this->compileStmt($stmt->body);

        // continue → re-test condition
        $this->patchContinues($loopStart);

        $this->emit(OpCode::Jump, $loopStart);
        $this->patchJump($exitJump);
        $this->patchBreaks();
    }

    private function compileFor(ForStmt $stmt): void
    {
        $needsScope = $stmt->init instanceof VarDeclaration
            && $stmt->init->kind !== VarKind::Var
            && !isset($this->regMap[$stmt->init->name]);

        if ($needsScope) {
            $this->emit(OpCode::PushScope);
        }

        // Init
        if ($stmt->init instanceof VarDeclaration) {
            $this->compileVarDeclaration($stmt->init);
        } elseif ($stmt->init instanceof ExpressionStmt) {
            $this->compileExpr($stmt->init->expression);
            $this->emit(OpCode::Pop);
        }

        $loopStart = count($this->ops);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        // Condition
        $exitJump = -1;
        if ($stmt->condition !== null) {
            $this->compileExpr($stmt->condition);
            $exitJump = $this->emitJump(OpCode::JumpIfFalse);
        }

        // Body
        $this->compileStmt($stmt->body);

        // continue → jump to update (not condition)
        $updateStart = count($this->ops);
        $this->patchContinues($updateStart);

        // Update
        if ($stmt->update !== null) {
            $this->compileExpr($stmt->update);
            $this->emit(OpCode::Pop);
        }

        $this->emit(OpCode::Jump, $loopStart);

        if ($exitJump >= 0) {
            $this->patchJump($exitJump);
        }

        $this->patchBreaks();

        if ($needsScope) {
            $this->emit(OpCode::PopScope);
        }
    }

    /**
     * for (var/let/const x of iterable) { body }
     *
     * Desugars to: var __arr = iterable; var __i = 0; while (__i < __arr.length) { x = __arr[__i]; body; __i++; }
     * But implemented directly in bytecode using index iteration over JsArray elements.
     */
    private function compileForOf(ForOfStmt $stmt): void
    {
        $needsScope = $stmt->kind !== VarKind::Var;
        if ($needsScope) {
            $this->emit(OpCode::PushScope);
        }

        // Push iterable onto stack, store in temp var
        $uid = $this->tempCounter++;
        $this->compileExpr($stmt->iterable);
        $arrName = $this->addName("__forof_arr{$uid}");
        $this->emit(OpCode::DefineVar, $arrName, 0);

        // Init index = 0
        $this->emit(OpCode::Const, $this->addConstant(0.0));
        $idxName = $this->addName("__forof_idx{$uid}");
        $this->emit(OpCode::DefineVar, $idxName, 0);

        // Loop start
        $loopStart = count($this->ops);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        // Condition: __forof_idx < __forof_arr.length
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::GetLocal, $arrName);
        $this->emit(OpCode::Const, $this->addConstant('length'));
        $this->emit(OpCode::GetProperty);
        $this->emit(OpCode::Lt);
        $exitJump = $this->emitJump(OpCode::JumpIfFalse);

        // x = __forof_arr[__forof_idx]
        $this->emit(OpCode::GetLocal, $arrName);
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::GetProperty);
        if (isset($this->regMap[$stmt->name])) {
            $this->emit(OpCode::SetReg, $this->regMap[$stmt->name]);
        } else {
            $varName = $this->addName($stmt->name);
            $kindVal = match ($stmt->kind) {
                VarKind::Var => 0, VarKind::Let => 1, VarKind::Const => 2,
            };
            $this->emit(OpCode::DefineVar, $varName, $kindVal);
        }

        // Body
        $this->compileStmt($stmt->body);

        // Continue point
        $updateStart = count($this->ops);
        $this->patchContinues($updateStart);

        // __forof_idx++
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::Const, $this->addConstant(1.0));
        $this->emit(OpCode::Add);
        $this->emit(OpCode::SetLocal, $idxName);

        $this->emit(OpCode::Jump, $loopStart);
        $this->patchJump($exitJump);
        $this->patchBreaks();

        if ($needsScope) {
            $this->emit(OpCode::PopScope);
        }
    }

    /**
     * for (var/let/const x in object) { body }
     *
     * Gets Object.keys() and iterates over them.
     */
    private function compileForIn(ForInStmt $stmt): void
    {
        $needsScope = $stmt->kind !== VarKind::Var;
        if ($needsScope) {
            $this->emit(OpCode::PushScope);
        }

        // Push Object.keys(object) to get array of keys
        $uid = $this->tempCounter++;
        $this->emit(OpCode::GetLocal, $this->addName('Object'));
        $this->emit(OpCode::Const, $this->addConstant('keys'));
        $this->emit(OpCode::GetProperty);
        $this->compileExpr($stmt->object);
        $this->emit(OpCode::Call, 1);
        $arrName = $this->addName("__forin_arr{$uid}");
        $this->emit(OpCode::DefineVar, $arrName, 0);

        // Init index = 0
        $this->emit(OpCode::Const, $this->addConstant(0.0));
        $idxName = $this->addName("__forin_idx{$uid}");
        $this->emit(OpCode::DefineVar, $idxName, 0);

        // Loop start
        $loopStart = count($this->ops);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        // Condition: __forin_idx < __forin_arr.length
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::GetLocal, $arrName);
        $this->emit(OpCode::Const, $this->addConstant('length'));
        $this->emit(OpCode::GetProperty);
        $this->emit(OpCode::Lt);
        $exitJump = $this->emitJump(OpCode::JumpIfFalse);

        // x = __forin_arr[__forin_idx]
        $this->emit(OpCode::GetLocal, $arrName);
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::GetProperty);
        if (isset($this->regMap[$stmt->name])) {
            $this->emit(OpCode::SetReg, $this->regMap[$stmt->name]);
        } else {
            $varName = $this->addName($stmt->name);
            $kindVal = match ($stmt->kind) {
                VarKind::Var => 0, VarKind::Let => 1, VarKind::Const => 2,
            };
            $this->emit(OpCode::DefineVar, $varName, $kindVal);
        }

        // Body
        $this->compileStmt($stmt->body);

        // Continue point
        $updateStart = count($this->ops);
        $this->patchContinues($updateStart);

        // __forin_idx++
        $this->emit(OpCode::GetLocal, $idxName);
        $this->emit(OpCode::Const, $this->addConstant(1.0));
        $this->emit(OpCode::Add);
        $this->emit(OpCode::SetLocal, $idxName);

        $this->emit(OpCode::Jump, $loopStart);
        $this->patchJump($exitJump);
        $this->patchBreaks();

        if ($needsScope) {
            $this->emit(OpCode::PopScope);
        }
    }

    private function compileDestructuring(DestructuringDeclaration $decl): void
    {
        // Evaluate initializer once, store in temp
        $uid = $this->tempCounter++;
        $this->compileExpr($decl->initializer);
        $tmpName = $this->addName("__destr_tmp{$uid}");
        $this->emit(OpCode::DefineVar, $tmpName, 0);

        // Extract each binding
        foreach ($decl->bindings as $binding) {
            $this->emit(OpCode::GetLocal, $tmpName);
            if ($decl->isArray) {
                // Array: access by index
                $this->emit(OpCode::Const, $this->addConstant((float) $binding['source']));
            } else {
                // Object: access by key
                $this->emit(OpCode::Const, $this->addConstant($binding['source']));
            }
            $this->emit(OpCode::GetProperty);

            // Default value handling
            if ($binding['default'] !== null) {
                $this->emit(OpCode::Dup);
                $this->emit(OpCode::Const, $this->addConstant(JsUndefined::Value));
                $this->emit(OpCode::StrictEq);
                $skipDefault = $this->emitJump(OpCode::JumpIfFalse);
                $this->emit(OpCode::Pop); // discard undefined
                $this->compileExpr($binding['default']);
                $this->patchJump($skipDefault);
            }

            // Store in variable
            if (isset($this->regMap[$binding['name']])) {
                $this->emit(OpCode::SetReg, $this->regMap[$binding['name']]);
            } else {
                $nameIdx = $this->addName($binding['name']);
                $kindVal = match ($decl->kind) {
                    VarKind::Var => 0, VarKind::Let => 1, VarKind::Const => 2,
                };
                $this->emit(OpCode::DefineVar, $nameIdx, $kindVal);
            }
        }

        // Handle rest element
        if ($decl->restName !== null && $decl->isArray) {
            // rest = arr.slice(bindingCount)
            $this->emit(OpCode::GetLocal, $tmpName);
            $this->emit(OpCode::Const, $this->addConstant('slice'));
            $this->emit(OpCode::GetProperty);
            $this->emit(OpCode::Const, $this->addConstant((float) count($decl->bindings)));
            $this->emit(OpCode::Call, 1);
            if (isset($this->regMap[$decl->restName])) {
                $this->emit(OpCode::SetReg, $this->regMap[$decl->restName]);
            } else {
                $nameIdx = $this->addName($decl->restName);
                $kindVal = match ($decl->kind) {
                    VarKind::Var => 0, VarKind::Let => 1, VarKind::Const => 2,
                };
                $this->emit(OpCode::DefineVar, $nameIdx, $kindVal);
            }
        }
    }

    private function compileDoWhile(DoWhileStmt $stmt): void
    {
        $bodyStart = count($this->ops);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        $this->compileStmt($stmt->body);

        // continue → jump to condition
        $condStart = count($this->ops);
        $this->patchContinues($condStart);

        $this->compileExpr($stmt->condition);
        $this->emit(OpCode::JumpIfTrue, $bodyStart);

        $this->patchBreaks();
    }

    private function compileBreak(): void
    {
        if (empty($this->loopStack)) {
            throw new RuntimeException('break outside of loop or switch');
        }
        $this->loopStack[count($this->loopStack) - 1]['breakPatches'][] = $this->emitJump(OpCode::Jump);
    }

    private function compileContinue(): void
    {
        if (empty($this->loopStack)) {
            throw new RuntimeException('continue outside of loop');
        }
        $this->loopStack[count($this->loopStack) - 1]['continuePatches'][] = $this->emitJump(OpCode::Jump);
    }

    private function compileSwitch(SwitchStmt $stmt): void
    {
        // Compile discriminant — stays on stack throughout
        $this->compileExpr($stmt->discriminant);
        $this->loopStack[] = ['continuePatches' => [], 'breakPatches' => []];

        // Phase 1: Jump table — emit comparisons for each case
        $caseJumps = [];
        $defaultIndex = -1;
        foreach ($stmt->cases as $i => $case) {
            if ($case->test !== null) {
                $this->emit(OpCode::Dup);
                $this->compileExpr($case->test);
                $this->emit(OpCode::StrictEq);
                $caseJumps[$i] = $this->emitJump(OpCode::JumpIfTrue);
            } else {
                $defaultIndex = $i;
            }
        }

        // After all comparisons: jump to default or end
        $defaultJump = -1;
        $endJump = -1;
        if ($defaultIndex >= 0) {
            $defaultJump = $this->emitJump(OpCode::Jump);
        } else {
            $endJump = $this->emitJump(OpCode::Jump);
        }

        // Phase 2: Case bodies (sequential for fall-through)
        foreach ($stmt->cases as $i => $case) {
            if (isset($caseJumps[$i])) {
                $this->patchJump($caseJumps[$i]);
            }
            if ($i === $defaultIndex && $defaultJump >= 0) {
                $this->patchJump($defaultJump);
            }
            foreach ($case->consequent as $consequentStmt) {
                $this->compileStmt($consequentStmt);
            }
        }

        if ($endJump >= 0) {
            $this->patchJump($endJump);
        }

        // Pop discriminant
        $this->emit(OpCode::Pop);
        $this->patchBreaks();
    }

    /** Patch all continue jumps in the current loop to the given target, then pop the stack. */
    private function patchContinues(int $target): void
    {
        $loop = &$this->loopStack[count($this->loopStack) - 1];
        foreach ($loop['continuePatches'] as $idx) {
            $this->opA[$idx] = $target;
        }
    }

    /** Patch all break jumps in the current loop to the current IP, then pop the stack. */
    private function patchBreaks(): void
    {
        $loop = array_pop($this->loopStack);
        $target = count($this->ops);
        foreach ($loop['breakPatches'] as $idx) {
            $this->opA[$idx] = $target;
        }
    }

    private function compileThrow(ThrowStmt $stmt): void
    {
        $this->compileExpr($stmt->argument);
        $this->emit(OpCode::Throw);
    }

    private function compileTryCatch(TryCatchStmt $stmt): void
    {
        // SetCatch with placeholder for catch handler IP
        $setCatchIdx = $this->emit(OpCode::SetCatch, 0xFFFF);

        // Compile try body
        foreach ($stmt->block->statements as $s) {
            $this->compileStmt($s);
        }

        // Remove handler on normal completion
        $this->emit(OpCode::PopCatch);
        $skipCatch = $this->emitJump(OpCode::Jump);

        // Catch handler starts here — patch SetCatch to point here
        $this->opA[$setCatchIdx] = count($this->ops);

        if ($stmt->handler !== null) {
            // Catch param is block-scoped
            $this->emit(OpCode::PushScope);
            $nameIdx = $this->addName($stmt->handler->param);
            $this->emit(OpCode::DefineVar, $nameIdx, 1); // 1 = let — pops exception value from stack

            foreach ($stmt->handler->body->statements as $s) {
                $this->compileStmt($s);
            }
            $this->emit(OpCode::PopScope);
        } else {
            // No catch clause — just discard the exception value from stack
            $this->emit(OpCode::Pop);
        }

        $this->patchJump($skipCatch);
    }

    // ──────────────────── Expression compilation ────────────────────

    private function compileExpr(Expr $expr): void
    {
        match (true) {
            $expr instanceof NumberLiteral => $this->emit(OpCode::Const, $this->addConstant($expr->value)),
            $expr instanceof StringLiteral => $this->emit(OpCode::Const, $this->addConstant($expr->value)),
            $expr instanceof BooleanLiteral => $this->emit(OpCode::Const, $this->addConstant($expr->value)),
            $expr instanceof NullLiteral => $this->emit(OpCode::Const, $this->addConstant(null)),
            $expr instanceof UndefinedLiteral => $this->emit(OpCode::Const, $this->addConstant(JsUndefined::Value)),
            $expr instanceof Identifier => $this->compileIdentifier($expr),
            $expr instanceof BinaryExpr => $this->compileBinary($expr),
            $expr instanceof UnaryExpr => $this->compileUnary($expr),
            $expr instanceof AssignExpr => $this->compileAssign($expr),
            $expr instanceof CallExpr => $this->compileCall($expr),
            $expr instanceof FunctionExpr => $this->compileFunctionExpr($expr),
            $expr instanceof LogicalExpr => $this->compileLogical($expr),
            $expr instanceof ConditionalExpr => $this->compileConditional($expr),
            $expr instanceof TypeofExpr => $this->compileTypeof($expr),
            $expr instanceof ArrayLiteral => $this->compileArrayLiteral($expr),
            $expr instanceof ObjectLiteral => $this->compileObjectLiteral($expr),
            $expr instanceof MemberExpr => $this->compileMemberExpr($expr),
            $expr instanceof MemberAssignExpr => $this->compileMemberAssign($expr),
            $expr instanceof ThisExpr => $this->emit(OpCode::GetLocal, $this->addName('this')),
            $expr instanceof NewExpr => $this->compileNew($expr),
            $expr instanceof RegexLiteral => $this->compileRegex($expr),
            $expr instanceof TemplateLiteral => $this->compileTemplateLiteral($expr),
            $expr instanceof UpdateExpr => $this->compileUpdate($expr),
            $expr instanceof VoidExpr => $this->compileVoid($expr),
            $expr instanceof DeleteExpr => $this->compileDelete($expr),
            default => throw new RuntimeException('Unknown expression type: ' . get_class($expr)),
        };
    }

    private function compileConditional(ConditionalExpr $expr): void
    {
        $this->compileExpr($expr->condition);
        $jumpIfFalse = $this->emitJump(OpCode::JumpIfFalse);

        $this->compileExpr($expr->consequent);
        $jumpOver = $this->emitJump(OpCode::Jump);

        $this->patchJump($jumpIfFalse);
        $this->compileExpr($expr->alternate);

        $this->patchJump($jumpOver);
    }

    private function compileTypeof(TypeofExpr $expr): void
    {
        // typeof <identifier> must not throw on undeclared variables (ES spec)
        if ($expr->operand instanceof Identifier) {
            if (isset($this->regMap[$expr->operand->name])) {
                $this->emit(OpCode::GetReg, $this->regMap[$expr->operand->name]);
                $this->emit(OpCode::Typeof);
            } else {
                $this->emit(OpCode::TypeofVar, $this->addName($expr->operand->name));
            }
            return;
        }
        $this->compileExpr($expr->operand);
        $this->emit(OpCode::Typeof);
    }

    private function compileArrayLiteral(ArrayLiteral $expr): void
    {
        $hasSpread = false;
        foreach ($expr->elements as $el) {
            if ($el instanceof SpreadElement) { $hasSpread = true; break; }
        }

        if (!$hasSpread) {
            // Fast path: no spread, use existing MakeArray
            foreach ($expr->elements as $el) {
                $this->compileExpr($el);
            }
            $this->emit(OpCode::MakeArray, count($expr->elements));
            return;
        }

        // Spread path: build array incrementally
        $this->emit(OpCode::MakeArray, 0);
        foreach ($expr->elements as $el) {
            if ($el instanceof SpreadElement) {
                $this->compileExpr($el->argument);
                $this->emit(OpCode::ArraySpread);
            } else {
                $this->compileExpr($el);
                $this->emit(OpCode::ArrayPush);
            }
        }
    }

    private function compileObjectLiteral(ObjectLiteral $expr): void
    {
        foreach ($expr->properties as $prop) {
            if ($prop->computed && $prop->computedKey !== null) {
                $this->compileExpr($prop->computedKey);
            } else {
                $this->emit(OpCode::Const, $this->addConstant($prop->key));
            }
            $this->compileExpr($prop->value);
        }
        $this->emit(OpCode::MakeObject, count($expr->properties));
    }

    private function compileMemberExpr(MemberExpr $expr): void
    {
        $this->compileExpr($expr->object);
        if ($expr->computed) {
            $this->compileExpr($expr->property);
        } else {
            // Non-computed: property is an Identifier, push its name as string constant
            assert($expr->property instanceof Identifier);
            $this->emit(OpCode::Const, $this->addConstant($expr->property->name));
        }
        $useOpt = $expr->optional || $expr->optionalChain;
        $this->emit($useOpt ? OpCode::GetPropertyOpt : OpCode::GetProperty);
    }

    private function compileMemberAssign(MemberAssignExpr $expr): void
    {
        $this->compileExpr($expr->object);
        if ($expr->computed) {
            $this->compileExpr($expr->property);
        } else {
            assert($expr->property instanceof Identifier);
            $this->emit(OpCode::Const, $this->addConstant($expr->property->name));
        }

        if ($expr->operator === '=') {
            $this->compileExpr($expr->value);
        } else {
            if ($expr->operator === '??=') {
                // Short-circuit: obj[key] ??= val
                // Read current → if not nullish, skip assignment
                $this->compileExpr($expr->object);
                if ($expr->computed) {
                    $this->compileExpr($expr->property);
                } else {
                    assert($expr->property instanceof Identifier);
                    $this->emit(OpCode::Const, $this->addConstant($expr->property->name));
                }
                $this->emit(OpCode::GetProperty);
                $this->emit(OpCode::Dup);
                $skip = $this->emitJump(OpCode::JumpIfNotNullish);
                $this->emit(OpCode::Pop); // discard old value

                // Re-emit obj+key for SetProperty
                $this->compileExpr($expr->object);
                if ($expr->computed) {
                    $this->compileExpr($expr->property);
                } else {
                    assert($expr->property instanceof Identifier);
                    $this->emit(OpCode::Const, $this->addConstant($expr->property->name));
                }
                $this->compileExpr($expr->value);
                $this->emit(OpCode::SetProperty);
                $this->patchJump($skip);
                return;
            }

            // Compound assignment: obj[key] += val
            // Stack: [obj, key]. Re-evaluate object+key for the read (GetProperty).
            // After GetProperty + compute, SetProperty consumes [obj, key, newVal].
            $this->compileExpr($expr->object);
            if ($expr->computed) {
                $this->compileExpr($expr->property);
            } else {
                assert($expr->property instanceof Identifier);
                $this->emit(OpCode::Const, $this->addConstant($expr->property->name));
            }
            $this->emit(OpCode::GetProperty);
            $this->compileExpr($expr->value);
            $op = match ($expr->operator) {
                '+='   => OpCode::Add,
                '-='   => OpCode::Sub,
                '*='   => OpCode::Mul,
                '/='   => OpCode::Div,
                '%='   => OpCode::Mod,
                '**='  => OpCode::Exp,
                '&='   => OpCode::BitAnd,
                '|='   => OpCode::BitOr,
                '^='   => OpCode::BitXor,
                '<<='  => OpCode::Shl,
                '>>='  => OpCode::Shr,
                '>>>=' => OpCode::Ushr,
                default => throw new RuntimeException("Unknown assign op: {$expr->operator}"),
            };
            $this->emit($op);
        }

        $this->emit(OpCode::SetProperty); // pops value, key, obj → pushes value
    }

    private function compileIdentifier(Identifier $id): void
    {
        if (isset($this->regMap[$id->name])) {
            $this->emit(OpCode::GetReg, $this->regMap[$id->name]);
        } else {
            $this->emit(OpCode::GetLocal, $this->addName($id->name));
        }
    }

    private function compileBinary(BinaryExpr $expr): void
    {
        $this->compileExpr($expr->left);
        $this->compileExpr($expr->right);

        $op = match ($expr->operator) {
            '+'   => OpCode::Add,
            '-'   => OpCode::Sub,
            '*'   => OpCode::Mul,
            '/'   => OpCode::Div,
            '%'   => OpCode::Mod,
            '**'  => OpCode::Exp,
            '&'   => OpCode::BitAnd,
            '|'   => OpCode::BitOr,
            '^'   => OpCode::BitXor,
            '<<'  => OpCode::Shl,
            '>>'  => OpCode::Shr,
            '>>>' => OpCode::Ushr,
            '=='  => OpCode::Eq,
            '!='  => OpCode::Neq,
            '===' => OpCode::StrictEq,
            '!==' => OpCode::StrictNeq,
            '<'   => OpCode::Lt,
            '<='  => OpCode::Lte,
            '>'   => OpCode::Gt,
            '>='  => OpCode::Gte,
            'in'  => OpCode::HasProp,
            'instanceof' => OpCode::InstanceOf,
            default => throw new RuntimeException("Unknown binary operator: {$expr->operator}"),
        };

        $this->emit($op);
    }

    private function compileUnary(UnaryExpr $expr): void
    {
        $this->compileExpr($expr->operand);

        match ($expr->operator) {
            '-' => $this->emit(OpCode::Negate),
            '!' => $this->emit(OpCode::Not),
            '~' => $this->emit(OpCode::BitNot),
            default => throw new RuntimeException("Unknown unary operator: {$expr->operator}"),
        };
    }

    private function compileUpdate(UpdateExpr $expr): void
    {
        $incOp = $expr->operator === '++' ? OpCode::Add : OpCode::Sub;

        if ($expr->argument instanceof Identifier) {
            $name = $expr->argument->name;
            $isReg = isset($this->regMap[$name]);
            $emitGet = fn() => $isReg
                ? $this->emit(OpCode::GetReg, $this->regMap[$name])
                : $this->emit(OpCode::GetLocal, $this->addName($name));
            $emitSet = fn() => $isReg
                ? $this->emit(OpCode::SetReg, $this->regMap[$name])
                : $this->emit(OpCode::SetLocal, $this->addName($name));

            if ($expr->prefix) {
                // ++x: load, add 1, dup (keep new), store
                $emitGet();
                $this->emit(OpCode::Const, $this->addConstant(1));
                $this->emit($incOp);
                $this->emit(OpCode::Dup);
                $emitSet();
            } else {
                // x++: load, dup (keep old), add 1, store → old remains
                $emitGet();
                $this->emit(OpCode::Dup);
                $this->emit(OpCode::Const, $this->addConstant(1));
                $this->emit($incOp);
                $emitSet();
            }
        } elseif ($expr->argument instanceof MemberExpr) {
            $emitObjKey = function () use ($expr): void {
                $this->compileExpr($expr->argument->object);
                if ($expr->argument->computed) {
                    $this->compileExpr($expr->argument->property);
                } else {
                    assert($expr->argument->property instanceof Identifier);
                    $this->emit(OpCode::Const, $this->addConstant($expr->argument->property->name));
                }
            };

            if ($expr->prefix) {
                // ++obj.x: [obj, key] for SetProperty, then get+inc as value
                $emitObjKey();        // stack: [obj, key]
                $emitObjKey();        // stack: [obj, key, obj, key]
                $this->emit(OpCode::GetProperty); // stack: [obj, key, old]
                $this->emit(OpCode::Const, $this->addConstant(1));
                $this->emit($incOp);  // stack: [obj, key, new]
                $this->emit(OpCode::SetProperty); // pops [obj,key,new], pushes new
            } else {
                // obj.x++: need old value as result
                $emitObjKey();
                $this->emit(OpCode::GetProperty); // stack: [old]
                $emitObjKey();        // stack: [old, obj, key]
                $emitObjKey();        // stack: [old, obj, key, obj, key]
                $this->emit(OpCode::GetProperty); // stack: [old, obj, key, old2]
                $this->emit(OpCode::Const, $this->addConstant(1));
                $this->emit($incOp);  // stack: [old, obj, key, new]
                $this->emit(OpCode::SetProperty); // stack: [old, new]
                $this->emit(OpCode::Pop); // stack: [old]
            }
        }
    }

    private function compileVoid(VoidExpr $expr): void
    {
        $this->compileExpr($expr->operand);
        $this->emit(OpCode::Pop);
        $this->emit(OpCode::Const, $this->addConstant(JsUndefined::Value));
    }

    private function compileDelete(DeleteExpr $expr): void
    {
        if ($expr->operand instanceof MemberExpr) {
            $this->compileExpr($expr->operand->object);
            if ($expr->operand->computed) {
                $this->compileExpr($expr->operand->property);
            } else {
                assert($expr->operand->property instanceof Identifier);
                $this->emit(OpCode::Const, $this->addConstant($expr->operand->property->name));
            }
            $this->emit(OpCode::DeleteProp);
        } else {
            // delete on non-member: evaluate for side effects, return true
            if (!($expr->operand instanceof Identifier)) {
                $this->compileExpr($expr->operand);
                $this->emit(OpCode::Pop);
            }
            $this->emit(OpCode::Const, $this->addConstant(true));
        }
    }

    private function compileAssign(AssignExpr $expr): void
    {
        $isReg = isset($this->regMap[$expr->name]);

        // ??= needs short-circuit: only assign if current value is nullish
        if ($expr->operator === '??=') {
            if ($isReg) {
                $this->emit(OpCode::GetReg, $this->regMap[$expr->name]);
            } else {
                $this->emit(OpCode::GetLocal, $this->addName($expr->name));
            }
            $this->emit(OpCode::Dup);
            $skipJump = $this->emitJump(OpCode::JumpIfNotNullish);
            $this->emit(OpCode::Pop); // discard nullish value
            $this->compileExpr($expr->value);
            $this->emit(OpCode::Dup);
            if ($isReg) {
                $this->emit(OpCode::SetReg, $this->regMap[$expr->name]);
            } else {
                $this->emit(OpCode::SetLocal, $this->addName($expr->name));
            }
            $this->patchJump($skipJump);
            return;
        }

        if ($expr->operator === '=') {
            $this->compileExpr($expr->value);
        } else {
            // Compound assignment: load current value, compute, store
            if ($isReg) {
                $this->emit(OpCode::GetReg, $this->regMap[$expr->name]);
            } else {
                $this->emit(OpCode::GetLocal, $this->addName($expr->name));
            }
            $this->compileExpr($expr->value);
            $op = match ($expr->operator) {
                '+='   => OpCode::Add,
                '-='   => OpCode::Sub,
                '*='   => OpCode::Mul,
                '/='   => OpCode::Div,
                '%='   => OpCode::Mod,
                '**='  => OpCode::Exp,
                '&='   => OpCode::BitAnd,
                '|='   => OpCode::BitOr,
                '^='   => OpCode::BitXor,
                '<<='  => OpCode::Shl,
                '>>='  => OpCode::Shr,
                '>>>=' => OpCode::Ushr,
                default => throw new RuntimeException("Unknown assign op: {$expr->operator}"),
            };
            $this->emit($op);
        }

        $this->emit(OpCode::Dup);        // keep value on stack (assignment is an expression)
        if ($isReg) {
            $this->emit(OpCode::SetReg, $this->regMap[$expr->name]);
        } else {
            $this->emit(OpCode::SetLocal, $this->addName($expr->name));
        }
    }

    private function compileCall(CallExpr $expr): void
    {
        $hasSpread = false;
        foreach ($expr->arguments as $a) {
            if ($a instanceof SpreadElement) { $hasSpread = true; break; }
        }

        $useOpt = $expr->optional || $expr->optionalChain;

        $this->compileExpr($expr->callee);

        if (!$hasSpread) {
            foreach ($expr->arguments as $arg) {
                $this->compileExpr($arg);
            }
            $this->emit($useOpt ? OpCode::CallOpt : OpCode::Call, count($expr->arguments));
        } else {
            // Spread path: build args array, then CallSpread
            $this->emit(OpCode::MakeArray, 0);
            foreach ($expr->arguments as $arg) {
                if ($arg instanceof SpreadElement) {
                    $this->compileExpr($arg->argument);
                    $this->emit(OpCode::ArraySpread);
                } else {
                    $this->compileExpr($arg);
                    $this->emit(OpCode::ArrayPush);
                }
            }
            $this->emit($useOpt ? OpCode::CallSpreadOpt : OpCode::CallSpread);
        }
    }

    private function compileNew(NewExpr $expr): void
    {
        $hasSpread = false;
        foreach ($expr->arguments as $a) {
            if ($a instanceof SpreadElement) { $hasSpread = true; break; }
        }

        $this->compileExpr($expr->callee);

        if (!$hasSpread) {
            foreach ($expr->arguments as $arg) {
                $this->compileExpr($arg);
            }
            $this->emit(OpCode::New, count($expr->arguments));
        } else {
            $this->emit(OpCode::MakeArray, 0);
            foreach ($expr->arguments as $arg) {
                if ($arg instanceof SpreadElement) {
                    $this->compileExpr($arg->argument);
                    $this->emit(OpCode::ArraySpread);
                } else {
                    $this->compileExpr($arg);
                    $this->emit(OpCode::ArrayPush);
                }
            }
            $this->emit(OpCode::NewSpread);
        }
    }

    private function compileFunctionExpr(FunctionExpr $expr): void
    {
        $descriptor = $this->compileFunction($expr->name, $expr->params, $expr->body, $expr->restParam, $expr->defaults);
        $descIdx    = $this->addConstant($descriptor);
        $this->emit(OpCode::MakeClosure, $descIdx);
    }

    private function compileRegex(RegexLiteral $expr): void
    {
        // JsRegex instances are never deduplicated (each literal creates a new object)
        $idx = count($this->constants);
        $this->constants[] = new JsRegex($expr->pattern, $expr->flags);
        $this->emit(OpCode::Const, $idx);
    }

    private function compileTemplateLiteral(TemplateLiteral $expr): void
    {
        // Start with first quasi to ensure string coercion via Add
        $this->emit(OpCode::Const, $this->addConstant($expr->quasis[0]));

        for ($i = 0; $i < count($expr->expressions); $i++) {
            $this->compileExpr($expr->expressions[$i]);
            $this->emit(OpCode::Add);

            if ($expr->quasis[$i + 1] !== '') {
                $this->emit(OpCode::Const, $this->addConstant($expr->quasis[$i + 1]));
                $this->emit(OpCode::Add);
            }
        }
    }

    private function compileLogical(LogicalExpr $expr): void
    {
        $this->compileExpr($expr->left);

        if ($expr->operator === '&&') {
            // Short-circuit: if left is falsy, skip right
            $this->emit(OpCode::Dup);
            $jumpIfFalse = $this->emitJump(OpCode::JumpIfFalse);
            $this->emit(OpCode::Pop); // discard left value
            $this->compileExpr($expr->right);
            $this->patchJump($jumpIfFalse);
        } elseif ($expr->operator === '??') {
            // Nullish coalescing: if left is not null/undefined, skip right
            $this->emit(OpCode::Dup);
            $jump = $this->emitJump(OpCode::JumpIfNotNullish);
            $this->emit(OpCode::Pop);
            $this->compileExpr($expr->right);
            $this->patchJump($jump);
        } else {
            // || : if left is truthy, skip right
            $this->emit(OpCode::Dup);
            $jumpIfTrue = $this->emitJump(OpCode::JumpIfTrue);
            $this->emit(OpCode::Pop);
            $this->compileExpr($expr->right);
            $this->patchJump($jumpIfTrue);
        }
    }

    // ──────────────────── Function compilation ────────────────────

    /**
     * Compile a function body in a fresh compiler context.
     *
     * @param string[] $params
     * @param Stmt[]   $body
     */
    private function compileFunction(?string $name, array $params, array $body, ?string $restParam = null, array $defaults = []): FunctionDescriptor
    {
        // Create a child compiler to get a fresh constant/name pool
        $child = new self();

        // Analyze locals for register allocation
        $child->analyzeLocals($params, $body, $restParam);

        // Emit default parameter assignments:
        // if (param === undefined) param = defaultExpr;
        foreach ($defaults as $i => $defaultExpr) {
            if ($defaultExpr === null) {
                continue;
            }
            $paramName = $params[$i];
            // Load the parameter value
            if (isset($child->regMap[$paramName])) {
                $child->emit(OpCode::GetReg, $child->regMap[$paramName]);
            } else {
                $child->emit(OpCode::GetVar, $child->addName($paramName));
            }
            // Check if it's undefined → jump over the default assignment
            $child->emit(OpCode::Const, $child->addConstant(JsUndefined::Value));
            $child->emit(OpCode::StrictNeq);
            $jumpIfDefined = $child->emitJump(OpCode::JumpIfTrue);
            // Param is undefined → assign the default
            $child->compileExpr($defaultExpr);
            if (isset($child->regMap[$paramName])) {
                $child->emit(OpCode::SetReg, $child->regMap[$paramName]);
            } else {
                $child->emit(OpCode::SetVar, $child->addName($paramName));
            }
            $child->emit(OpCode::Pop); // discard the set result
            $child->patchJump($jumpIfDefined);
        }

        // Hoist function declarations
        foreach ($body as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $child->compileFunctionDeclarationHoist($stmt);
            }
        }

        foreach ($body as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                continue;
            }
            $child->compileStmt($stmt);
        }

        // Implicit return undefined
        $child->emit(OpCode::Const, $child->addConstant(JsUndefined::Value));
        $child->emit(OpCode::Return);

        // Build paramSlots: register slot per param (-1 = environment-allocated)
        $paramSlots = [];
        foreach ($params as $p) {
            $paramSlots[] = $child->regMap[$p] ?? -1;
        }

        $restParamSlot = ($restParam !== null) ? ($child->regMap[$restParam] ?? -1) : -1;

        return new FunctionDescriptor(
            name: $name,
            params: $params,
            ops: $child->ops,
            opA: $child->opA,
            opB: $child->opB,
            constants: $child->constants,
            names: $child->names,
            regCount: $child->regCount,
            paramSlots: $paramSlots,
            restParam: $restParam,
            restParamSlot: $restParamSlot,
        );
    }

    // ──────────────────── Register allocation ────────────────────

    /**
     * Analyze a function body to determine which locals can use register slots.
     *
     * Strategy:
     * 1. Collect all local declarations (params + var/let/const)
     * 2. Collect all identifiers referenced inside inner functions (conservative)
     * 3. Variables NOT in the inner-function set → register slots
     * 4. Variables IN the inner-function set → Environment (captured)
     *
     * @param string[] $params
     * @param Stmt[]   $body
     */
    private function analyzeLocals(array $params, array $body, ?string $restParam = null): void
    {
        $this->regMap = [];
        $this->regCount = 0;

        // Step 1: Collect all local declarations
        $locals = [];
        foreach ($params as $p) {
            $locals[$p] = true;
        }
        if ($restParam !== null) {
            $locals[$restParam] = true;
        }
        self::collectDeclarations($body, $locals);

        // Step 2: Collect identifiers referenced inside inner functions
        $innerRefs = [];
        self::collectInnerFunctionRefs($body, $innerRefs);

        // Step 3: Assign register slots to non-captured params first (preserves order)
        foreach ($params as $p) {
            if (!isset($innerRefs[$p])) {
                $this->regMap[$p] = $this->regCount++;
            }
        }

        // Then remaining non-captured locals (includes restParam)
        foreach ($locals as $name => $_) {
            if (!isset($this->regMap[$name]) && !isset($innerRefs[$name])) {
                $this->regMap[$name] = $this->regCount++;
            }
        }
    }

    /**
     * Collect all register-eligible variable declarations at function scope level.
     * Only `var` declarations and function declarations are eligible (function-scoped).
     * `let`/`const` stay in Environment to preserve block scoping semantics.
     * Does NOT recurse into inner function bodies.
     *
     * @param Stmt[] $stmts
     * @param array<string, true> &$locals
     */
    private static function collectDeclarations(array $stmts, array &$locals): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof VarDeclaration && $stmt->kind === VarKind::Var) {
                $locals[$stmt->name] = true;
            } elseif ($stmt instanceof FunctionDeclaration) {
                $locals[$stmt->name] = true;
                // Don't recurse into function body
            } elseif ($stmt instanceof BlockStmt) {
                self::collectDeclarations($stmt->statements, $locals);
            } elseif ($stmt instanceof IfStmt) {
                self::collectDeclarations([$stmt->consequent], $locals);
                if ($stmt->alternate !== null) {
                    self::collectDeclarations([$stmt->alternate], $locals);
                }
            } elseif ($stmt instanceof WhileStmt) {
                self::collectDeclarations([$stmt->body], $locals);
            } elseif ($stmt instanceof ForStmt) {
                if ($stmt->init instanceof VarDeclaration && $stmt->init->kind === VarKind::Var) {
                    $locals[$stmt->init->name] = true;
                }
                self::collectDeclarations([$stmt->body], $locals);
            } elseif ($stmt instanceof ForOfStmt) {
                if ($stmt->kind === VarKind::Var) {
                    $locals[$stmt->name] = true;
                }
                self::collectDeclarations([$stmt->body], $locals);
            } elseif ($stmt instanceof ForInStmt) {
                if ($stmt->kind === VarKind::Var) {
                    $locals[$stmt->name] = true;
                }
                self::collectDeclarations([$stmt->body], $locals);
            } elseif ($stmt instanceof DestructuringDeclaration && $stmt->kind === VarKind::Var) {
                foreach ($stmt->bindings as $b) {
                    $locals[$b['name']] = true;
                }
                if ($stmt->restName !== null) {
                    $locals[$stmt->restName] = true;
                }
            } elseif ($stmt instanceof DoWhileStmt) {
                self::collectDeclarations([$stmt->body], $locals);
            } elseif ($stmt instanceof SwitchStmt) {
                foreach ($stmt->cases as $case) {
                    self::collectDeclarations($case->consequent, $locals);
                }
            } elseif ($stmt instanceof TryCatchStmt) {
                self::collectDeclarations([$stmt->block], $locals);
                if ($stmt->handler !== null) {
                    self::collectDeclarations([$stmt->handler->body], $locals);
                }
            }
        }
    }

    /**
     * Walk the function body to find all identifiers referenced inside inner functions.
     * This is a two-level walk:
     * - At the top level, we look for FunctionExpr and FunctionDeclaration nodes
     * - When found, we deeply collect all Identifier names from their bodies
     *
     * @param Stmt[] $stmts
     * @param array<string, true> &$refs
     */
    private static function collectInnerFunctionRefs(array $stmts, array &$refs): void
    {
        foreach ($stmts as $stmt) {
            self::walkForInnerFnRefs($stmt, $refs);
        }
    }

    /** @param Stmt|Expr $node */
    private static function walkForInnerFnRefs(mixed $node, array &$refs): void
    {
        // Inner function found — deeply collect all identifiers from its body
        if ($node instanceof FunctionDeclaration) {
            self::deepCollectIds($node->body, $refs);
            return;
        }
        if ($node instanceof FunctionExpr) {
            self::deepCollectIds($node->body, $refs);
            return;
        }

        // Recurse into children to find more inner functions
        if ($node instanceof ExpressionStmt) {
            self::walkForInnerFnRefs($node->expression, $refs);
        } elseif ($node instanceof VarDeclaration && $node->initializer !== null) {
            self::walkForInnerFnRefs($node->initializer, $refs);
        } elseif ($node instanceof ReturnStmt && $node->value !== null) {
            self::walkForInnerFnRefs($node->value, $refs);
        } elseif ($node instanceof BlockStmt) {
            foreach ($node->statements as $s) {
                self::walkForInnerFnRefs($s, $refs);
            }
        } elseif ($node instanceof IfStmt) {
            self::walkForInnerFnRefs($node->condition, $refs);
            self::walkForInnerFnRefs($node->consequent, $refs);
            if ($node->alternate !== null) {
                self::walkForInnerFnRefs($node->alternate, $refs);
            }
        } elseif ($node instanceof WhileStmt) {
            self::walkForInnerFnRefs($node->condition, $refs);
            self::walkForInnerFnRefs($node->body, $refs);
        } elseif ($node instanceof ForStmt) {
            if ($node->init instanceof VarDeclaration && $node->init->initializer !== null) {
                self::walkForInnerFnRefs($node->init->initializer, $refs);
            } elseif ($node->init instanceof ExpressionStmt) {
                self::walkForInnerFnRefs($node->init->expression, $refs);
            }
            if ($node->condition !== null) {
                self::walkForInnerFnRefs($node->condition, $refs);
            }
            if ($node->update !== null) {
                self::walkForInnerFnRefs($node->update, $refs);
            }
            self::walkForInnerFnRefs($node->body, $refs);
        } elseif ($node instanceof ForOfStmt) {
            self::walkForInnerFnRefs($node->iterable, $refs);
            self::walkForInnerFnRefs($node->body, $refs);
        } elseif ($node instanceof ForInStmt) {
            self::walkForInnerFnRefs($node->object, $refs);
            self::walkForInnerFnRefs($node->body, $refs);
        } elseif ($node instanceof DestructuringDeclaration) {
            self::walkForInnerFnRefs($node->initializer, $refs);
        } elseif ($node instanceof BinaryExpr) {
            self::walkForInnerFnRefs($node->left, $refs);
            self::walkForInnerFnRefs($node->right, $refs);
        } elseif ($node instanceof UnaryExpr) {
            self::walkForInnerFnRefs($node->operand, $refs);
        } elseif ($node instanceof LogicalExpr) {
            self::walkForInnerFnRefs($node->left, $refs);
            self::walkForInnerFnRefs($node->right, $refs);
        } elseif ($node instanceof ConditionalExpr) {
            self::walkForInnerFnRefs($node->condition, $refs);
            self::walkForInnerFnRefs($node->consequent, $refs);
            self::walkForInnerFnRefs($node->alternate, $refs);
        } elseif ($node instanceof AssignExpr) {
            self::walkForInnerFnRefs($node->value, $refs);
        } elseif ($node instanceof CallExpr) {
            self::walkForInnerFnRefs($node->callee, $refs);
            foreach ($node->arguments as $arg) {
                self::walkForInnerFnRefs($arg, $refs);
            }
        } elseif ($node instanceof ArrayLiteral) {
            foreach ($node->elements as $el) {
                self::walkForInnerFnRefs($el, $refs);
            }
        } elseif ($node instanceof ObjectLiteral) {
            foreach ($node->properties as $prop) {
                self::walkForInnerFnRefs($prop->value, $refs);
            }
        } elseif ($node instanceof MemberExpr) {
            self::walkForInnerFnRefs($node->object, $refs);
            if ($node->computed) {
                self::walkForInnerFnRefs($node->property, $refs);
            }
        } elseif ($node instanceof MemberAssignExpr) {
            self::walkForInnerFnRefs($node->object, $refs);
            if ($node->computed) {
                self::walkForInnerFnRefs($node->property, $refs);
            }
            self::walkForInnerFnRefs($node->value, $refs);
        } elseif ($node instanceof NewExpr) {
            self::walkForInnerFnRefs($node->callee, $refs);
            foreach ($node->arguments as $arg) {
                self::walkForInnerFnRefs($arg, $refs);
            }
        } elseif ($node instanceof TemplateLiteral) {
            foreach ($node->expressions as $e) {
                self::walkForInnerFnRefs($e, $refs);
            }
        } elseif ($node instanceof TypeofExpr) {
            self::walkForInnerFnRefs($node->operand, $refs);
        } elseif ($node instanceof UpdateExpr) {
            self::walkForInnerFnRefs($node->argument, $refs);
        } elseif ($node instanceof VoidExpr) {
            self::walkForInnerFnRefs($node->operand, $refs);
        } elseif ($node instanceof DeleteExpr) {
            self::walkForInnerFnRefs($node->operand, $refs);
        }
        // Literals and Identifier at the outer level — no inner functions
    }

    /**
     * Deeply collect ALL Identifier names from a function body (recursive).
     * Used to find all variables referenced inside an inner function.
     *
     * @param Stmt[] $stmts
     * @param array<string, true> &$refs
     */
    private static function deepCollectIds(array $stmts, array &$refs): void
    {
        foreach ($stmts as $stmt) {
            self::deepCollectStmt($stmt, $refs);
        }
    }

    private static function deepCollectStmt(Stmt $stmt, array &$refs): void
    {
        if ($stmt instanceof ExpressionStmt) {
            self::deepCollectExpr($stmt->expression, $refs);
        } elseif ($stmt instanceof VarDeclaration) {
            if ($stmt->initializer !== null) {
                self::deepCollectExpr($stmt->initializer, $refs);
            }
        } elseif ($stmt instanceof ReturnStmt) {
            if ($stmt->value !== null) {
                self::deepCollectExpr($stmt->value, $refs);
            }
        } elseif ($stmt instanceof BlockStmt) {
            self::deepCollectIds($stmt->statements, $refs);
        } elseif ($stmt instanceof IfStmt) {
            self::deepCollectExpr($stmt->condition, $refs);
            self::deepCollectStmt($stmt->consequent, $refs);
            if ($stmt->alternate !== null) {
                self::deepCollectStmt($stmt->alternate, $refs);
            }
        } elseif ($stmt instanceof WhileStmt) {
            self::deepCollectExpr($stmt->condition, $refs);
            self::deepCollectStmt($stmt->body, $refs);
        } elseif ($stmt instanceof ForStmt) {
            if ($stmt->init instanceof VarDeclaration && $stmt->init->initializer !== null) {
                self::deepCollectExpr($stmt->init->initializer, $refs);
            } elseif ($stmt->init instanceof ExpressionStmt) {
                self::deepCollectExpr($stmt->init->expression, $refs);
            }
            if ($stmt->condition !== null) {
                self::deepCollectExpr($stmt->condition, $refs);
            }
            if ($stmt->update !== null) {
                self::deepCollectExpr($stmt->update, $refs);
            }
            self::deepCollectStmt($stmt->body, $refs);
        } elseif ($stmt instanceof FunctionDeclaration) {
            // Recurse into nested inner functions too
            self::deepCollectIds($stmt->body, $refs);
        }
    }

    private static function deepCollectExpr(Expr $expr, array &$refs): void
    {
        if ($expr instanceof Identifier) {
            $refs[$expr->name] = true;
        } elseif ($expr instanceof AssignExpr) {
            $refs[$expr->name] = true;
            self::deepCollectExpr($expr->value, $refs);
        } elseif ($expr instanceof BinaryExpr) {
            self::deepCollectExpr($expr->left, $refs);
            self::deepCollectExpr($expr->right, $refs);
        } elseif ($expr instanceof UnaryExpr) {
            self::deepCollectExpr($expr->operand, $refs);
        } elseif ($expr instanceof LogicalExpr) {
            self::deepCollectExpr($expr->left, $refs);
            self::deepCollectExpr($expr->right, $refs);
        } elseif ($expr instanceof ConditionalExpr) {
            self::deepCollectExpr($expr->condition, $refs);
            self::deepCollectExpr($expr->consequent, $refs);
            self::deepCollectExpr($expr->alternate, $refs);
        } elseif ($expr instanceof CallExpr) {
            self::deepCollectExpr($expr->callee, $refs);
            foreach ($expr->arguments as $arg) {
                self::deepCollectExpr($arg, $refs);
            }
        } elseif ($expr instanceof FunctionExpr) {
            // Recurse into nested inner functions too
            foreach ($expr->body as $s) {
                self::deepCollectStmt($s, $refs);
            }
        } elseif ($expr instanceof ArrayLiteral) {
            foreach ($expr->elements as $el) {
                self::deepCollectExpr($el, $refs);
            }
        } elseif ($expr instanceof ObjectLiteral) {
            foreach ($expr->properties as $prop) {
                self::deepCollectExpr($prop->value, $refs);
            }
        } elseif ($expr instanceof MemberExpr) {
            self::deepCollectExpr($expr->object, $refs);
            if ($expr->computed) {
                self::deepCollectExpr($expr->property, $refs);
            }
        } elseif ($expr instanceof MemberAssignExpr) {
            self::deepCollectExpr($expr->object, $refs);
            if ($expr->computed) {
                self::deepCollectExpr($expr->property, $refs);
            }
            self::deepCollectExpr($expr->value, $refs);
        } elseif ($expr instanceof NewExpr) {
            self::deepCollectExpr($expr->callee, $refs);
            foreach ($expr->arguments as $arg) {
                self::deepCollectExpr($arg, $refs);
            }
        } elseif ($expr instanceof TemplateLiteral) {
            foreach ($expr->expressions as $e) {
                self::deepCollectExpr($e, $refs);
            }
        } elseif ($expr instanceof TypeofExpr) {
            self::deepCollectExpr($expr->operand, $refs);
        } elseif ($expr instanceof UpdateExpr) {
            self::deepCollectExpr($expr->argument, $refs);
        } elseif ($expr instanceof VoidExpr) {
            self::deepCollectExpr($expr->operand, $refs);
        } elseif ($expr instanceof DeleteExpr) {
            self::deepCollectExpr($expr->operand, $refs);
        }
        // Literals (Number, String, Boolean, Null, Undefined, This, Regex) → no identifiers
    }

    // ──────────────────── Emit helpers ────────────────────

    private function emit(OpCode $op, int $a = 0, int $b = 0): int
    {
        $idx = count($this->ops);
        $this->ops[] = $op;
        $this->opA[] = $a;
        $this->opB[] = $b;
        return $idx;
    }

    private function emitJump(OpCode $op): int
    {
        return $this->emit($op, 0xFFFF); // placeholder target
    }

    private function patchJump(int $instrIdx): void
    {
        $this->opA[$instrIdx] = count($this->ops);
    }

    private function addConstant(mixed $value): int
    {
        // FunctionDescriptor objects are never deduplicated
        if ($value instanceof FunctionDescriptor) {
            $idx = count($this->constants);
            $this->constants[] = $value;
            return $idx;
        }

        // Serialize key for dedup
        $key = serialize($value);
        if (isset($this->constMap[$key])) {
            return $this->constMap[$key];
        }

        $idx = count($this->constants);
        $this->constants[]   = $value;
        $this->constMap[$key] = $idx;
        return $idx;
    }

    private function addName(string $name): int
    {
        if (isset($this->nameMap[$name])) {
            return $this->nameMap[$name];
        }
        $idx = count($this->names);
        $this->names[]       = $name;
        $this->nameMap[$name] = $idx;
        return $idx;
    }
}
