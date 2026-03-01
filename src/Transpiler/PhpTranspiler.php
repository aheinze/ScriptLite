<?php

declare(strict_types=1);

namespace ScriptLite\Transpiler;

use ScriptLite\Ast\{
    ArrayLiteral, AssignExpr, BinaryExpr, BlockStmt, BooleanLiteral, BreakStmt, CallExpr,
    ConditionalExpr, ContinueStmt, DeleteExpr, DoWhileStmt, ExpressionStmt, Expr, ForStmt,
    FunctionDeclaration, FunctionExpr,
    Identifier, IfStmt, LogicalExpr, MemberAssignExpr, MemberExpr, NewExpr, NullLiteral,
    NumberLiteral, ObjectLiteral, Program, RegexLiteral, ReturnStmt, SpreadElement, Stmt, StringLiteral,
    SwitchStmt, TemplateLiteral, ThisExpr, ThrowStmt, TryCatchStmt, TypeofExpr, UnaryExpr,
    UndefinedLiteral, UpdateExpr, VoidExpr, VarDeclaration, VarKind, WhileStmt
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
    private int $tmpId = 0;

    /** @var list<array<string, true>> scope stack: each frame is set of variable names */
    private array $scopes = [];

    private bool $inConstructor = false;

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

    // ───────────────── Statements ─────────────────

    private function emitStmt(Stmt $stmt): string
    {
        return match (true) {
            $stmt instanceof ExpressionStmt => $this->emitExpr($stmt->expression) . ";\n",
            $stmt instanceof VarDeclaration => $this->emitVarDecl($stmt),
            $stmt instanceof FunctionDeclaration => $this->emitFuncDecl($stmt),
            $stmt instanceof ReturnStmt => $this->emitReturn($stmt),
            $stmt instanceof IfStmt => $this->emitIf($stmt),
            $stmt instanceof WhileStmt => $this->emitWhile($stmt),
            $stmt instanceof ForStmt => $this->emitFor($stmt),
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

    private function emitVarDecl(VarDeclaration $d): string
    {
        $this->addVar($d->name);
        $val = $d->initializer !== null ? $this->emitExpr($d->initializer) : 'null';
        return '$' . $d->name . ' = ' . $val . ";\n";
    }

    private function emitFuncDecl(FunctionDeclaration $d): string
    {
        $this->addVar($d->name);
        return '$' . $d->name . ' = ' . $this->emitClosure($d->name, $d->params, $d->body, restParam: $d->restParam) . ";\n";
    }

    private function emitReturn(ReturnStmt $s): string
    {
        if ($this->inConstructor) {
            $val = $s->value !== null ? $this->emitExpr($s->value) : 'null';
            // Constructor: if return value is not array/object, return $__this
            return "{ \$__rv = {$val}; return is_array(\$__rv) ? \$__rv : \$__this; }\n";
        }
        return 'return ' . ($s->value !== null ? $this->emitExpr($s->value) : 'null') . ";\n";
    }

    private function emitIf(IfStmt $s): string
    {
        $out = 'if (' . $this->emitExpr($s->condition) . ") {\n";
        $out .= $this->indent($this->emitStmt($s->consequent));
        $out .= '}';
        if ($s->alternate !== null) {
            $out .= " else {\n";
            $out .= $this->indent($this->emitStmt($s->alternate));
            $out .= '}';
        }
        return $out . "\n";
    }

    private function emitWhile(WhileStmt $s): string
    {
        return "while (" . $this->emitExpr($s->condition) . ") {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
    }

    private function emitFor(ForStmt $s): string
    {
        $init = '';
        if ($s->init instanceof VarDeclaration) {
            $this->addVar($s->init->name);
            $val = $s->init->initializer !== null ? $this->emitExpr($s->init->initializer) : 'null';
            $init = '$' . $s->init->name . ' = ' . $val;
        } elseif ($s->init instanceof ExpressionStmt) {
            $init = $this->emitExpr($s->init->expression);
        }

        $cond = $s->condition !== null ? $this->emitExpr($s->condition) : '';
        $upd = $s->update !== null ? $this->emitExpr($s->update) : '';

        return "for ({$init}; {$cond}; {$upd}) {\n"
            . $this->indent($this->emitStmt($s->body))
            . "}\n";
    }

    private function emitDoWhile(DoWhileStmt $s): string
    {
        return "do {\n"
            . $this->indent($this->emitStmt($s->body))
            . "} while (" . $this->emitExpr($s->condition) . ");\n";
    }

    private function emitSwitch(SwitchStmt $s): string
    {
        // Use match(true) with === comparisons for ECMAScript strict equality semantics
        $disc = '$__sw' . ($this->tmpId++);
        $out = "{$disc} = " . $this->emitExpr($s->discriminant) . ";\n";
        $out .= "switch (true) {\n";
        foreach ($s->cases as $case) {
            if ($case->test !== null) {
                $out .= "case ({$disc} === " . $this->emitExpr($case->test) . "):\n";
            } else {
                $out .= "default:\n";
            }
            foreach ($case->consequent as $stmt) {
                $out .= $this->indent($this->emitStmt($stmt));
            }
        }
        $out .= "}\n";
        return $out;
    }

    private function emitThrow(ThrowStmt $s): string
    {
        return "throw new \\ScriptLite\\Vm\\JsThrowable(" . $this->emitExpr($s->argument) . ");\n";
    }

    private function emitTryCatch(TryCatchStmt $s): string
    {
        $out = "try {\n" . $this->indent($this->emitBlock($s->block)) . "}";
        if ($s->handler !== null) {
            $this->addVar($s->handler->param);
            $out .= " catch (\\Throwable \$__ex) {\n";
            $out .= $this->indent(
                '$' . $s->handler->param . " = \$__ex instanceof \\ScriptLite\\Vm\\JsThrowable ? \$__ex->value : \$__ex->getMessage();\n"
                . $this->emitBlock($s->handler->body)
            );
            $out .= "}\n";
        }
        return $out;
    }

    private function emitBlock(BlockStmt $b): string
    {
        $out = '';
        foreach ($b->statements as $s) {
            $out .= $this->emitStmt($s);
        }
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
            $e instanceof Identifier => '$' . $e->name,
            $e instanceof BinaryExpr => $this->emitBinary($e),
            $e instanceof UnaryExpr => $this->emitUnary($e),
            $e instanceof AssignExpr => $this->emitAssign($e),
            $e instanceof CallExpr => $this->emitCall($e),
            $e instanceof FunctionExpr => $this->emitClosure($e->name, $e->params, $e->body, $e->isArrow, $e->restParam),
            $e instanceof LogicalExpr => $this->emitLogical($e),
            $e instanceof ConditionalExpr => '(' . $this->emitExpr($e->condition) . ' ? ' . $this->emitExpr($e->consequent) . ' : ' . $this->emitExpr($e->alternate) . ')',
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
            if ($e->left instanceof StringLiteral || $e->right instanceof StringLiteral
                || $e->left instanceof TemplateLiteral || $e->right instanceof TemplateLiteral) {
                return '((string)(' . $l . ') . (string)(' . $r . '))';
            }
            if ($e->left instanceof NumberLiteral && $e->right instanceof NumberLiteral) {
                return '(' . $l . ' + ' . $r . ')';
            }
            $ta = '$__t' . $this->tmpId++;
            $tb = '$__t' . $this->tmpId++;
            return "(is_string({$ta} = ({$l})) | is_string({$tb} = ({$r})) ? (string){$ta} . (string){$tb} : {$ta} + {$tb})";
        }

        if ($e->operator === '>>>') {
            return '(((int)(' . $l . ') & 0xFFFFFFFF) >> ((int)(' . $r . ') & 0x1F))';
        }

        if ($e->operator === 'in') {
            $to = '$__t' . $this->tmpId++;
            return "(({$to} = {$r}) instanceof \\ScriptLite\\Runtime\\JsObject ? array_key_exists((string)({$l}), {$to}->properties) : false)";
        }

        if ($e->operator === 'instanceof') {
            return "({$l} instanceof \\ScriptLite\\Runtime\\JsObject && {$r} instanceof \\ScriptLite\\Runtime\\JsClosure && {$l}->constructor === {$r})";
        }

        $op = match ($e->operator) {
            '-', '*', '/', '%', '**' => $e->operator,
            '&', '|', '^', '<<', '>>' => $e->operator,
            '==' => '==',
            '!=' => '!=',
            '===' => '===',
            '!==' => '!==',
            '<', '<=', '>', '>=' => $e->operator,
            default => throw new RuntimeException("Transpiler: unknown binary op {$e->operator}"),
        };
        return '(' . $l . ' ' . $op . ' ' . $r . ')';
    }

    private function emitUnary(UnaryExpr $e): string
    {
        return match ($e->operator) {
            '-' => '(-' . $this->emitExpr($e->operand) . ')',
            '!' => '(!' . $this->emitExpr($e->operand) . ')',
            '~' => '(~(int)' . $this->emitExpr($e->operand) . ')',
            default => throw new RuntimeException("Transpiler: unknown unary op {$e->operator}"),
        };
    }

    private function emitUpdate(UpdateExpr $e): string
    {
        if ($e->argument instanceof Identifier) {
            $var = '$' . $e->argument->name;
            $op = $e->operator === '++' ? '++' : '--';
            return $e->prefix ? "({$op}{$var})" : "({$var}{$op})";
        }
        if ($e->argument instanceof MemberExpr) {
            $obj = $this->emitExpr($e->argument->object);
            $key = $e->argument->computed
                ? $this->emitExpr($e->argument->property)
                : "'" . $e->argument->property->name . "'";
            $op = $e->operator === '++' ? '++' : '--';
            return $e->prefix ? "({$op}{$obj}[{$key}])" : "({$obj}[{$key}]{$op})";
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

    private function emitAssign(AssignExpr $e): string
    {
        $this->addVar($e->name);
        $val = $this->emitExpr($e->value);
        if ($e->operator === '=') {
            return '($' . $e->name . ' = ' . $val . ')';
        }
        if ($e->operator === '+=') {
            $ta = '$__t' . $this->tmpId++;
            $tb = '$__t' . $this->tmpId++;
            return '($' . $e->name . " = (is_string({$ta} = \${$e->name}) | is_string({$tb} = ({$val})) ? (string){$ta} . (string){$tb} : {$ta} + {$tb}))";
        }
        if ($e->operator === '??=') {
            return '($' . $e->name . ' = $' . $e->name . ' ?? ' . $val . ')';
        }
        if ($e->operator === '>>>=') {
            return '($' . $e->name . ' = ((int)$' . $e->name . ' & 0xFFFFFFFF) >> ((int)(' . $val . ') & 0x1F))';
        }
        $op = match ($e->operator) {
            '-=' => '-',
            '*=' => '*',
            '/=' => '/',
            '%=' => '%',
            '**=' => '**',
            '&=' => '&',
            '|=' => '|',
            '^=' => '^',
            '<<=' => '<<',
            '>>=' => '>>',
            default => throw new RuntimeException("Transpiler: unknown assign op {$e->operator}"),
        };
        return '($' . $e->name . ' = $' . $e->name . " {$op} {$val})";
    }

    private function emitLogical(LogicalExpr $e): string
    {
        if ($e->operator === '??') {
            // PHP ?? only works on variables; use temp var for arbitrary expressions
            $tmp = '$__nc' . ($this->tmpId++);
            return "(({$tmp} = " . $this->emitExpr($e->left) . ") === null ? " . $this->emitExpr($e->right) . " : {$tmp})";
        }
        return '(' . $this->emitExpr($e->left) . ' ' . $e->operator . ' ' . $this->emitExpr($e->right) . ')';
    }

    private function emitTypeof(TypeofExpr $e): string
    {
        $v = $this->emitExpr($e->operand);
        return "(function(\$v) { "
            . "if (\$v === null) return 'object'; "
            . "if (is_bool(\$v)) return 'boolean'; "
            . "if (is_int(\$v) || is_float(\$v)) return 'number'; "
            . "if (is_string(\$v)) return 'string'; "
            . "if (\$v instanceof \\Closure) return 'function'; "
            . "return 'object'; "
            . "})({$v})";
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
                $parts[] = $this->emitExpr($el->argument);
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
            return '[]';
        }
        $pairs = [];
        foreach ($e->properties as $prop) {
            $pairs[] = $this->escapeStr($prop->key) . ' => ' . $this->emitExpr($prop->value);
        }
        return '[' . implode(', ', $pairs) . ']';
    }

    private function emitMember(MemberExpr $e): string
    {
        $obj = $this->emitExpr($e->object);

        // Optional chaining: obj?.prop → temp var + null guard
        if ($e->optional) {
            $tmp = '$__oc' . ($this->tmpId++);
            if (!$e->computed && $e->property instanceof Identifier) {
                return "(({$tmp} = {$obj}) === null ? null : {$tmp}['{$e->property->name}'])";
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
                    default => "\${$e->object->name}['{$name}']",
                };
            }

            // .length → count() for arrays, strlen() for strings
            if ($name === 'length') {
                return "(is_string({$obj}) ? strlen({$obj}) : count({$obj}))";
            }

            return "{$obj}['{$name}']";
        }

        // Computed: obj[expr]
        $key = $this->emitExpr($e->property);
        return "{$obj}[{$key}]";
    }

    private function emitMemberAssign(MemberAssignExpr $e): string
    {
        $val = $this->emitExpr($e->value);
        $objCode = $e->object instanceof ThisExpr ? '$__this' : $this->emitExpr($e->object);

        if (!$e->computed && $e->property instanceof Identifier) {
            $key = "'" . $e->property->name . "'";
        } else {
            $key = $this->emitExpr($e->property);
        }

        if ($e->operator === '=') {
            return "({$objCode}[{$key}] = {$val})";
        }

        // Compound assignment: obj[key] += val
        if ($e->operator === '+=') {
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
        $op = match ($e->operator) {
            '-=' => '-', '*=' => '*', '/=' => '/',
            '%=' => '%', '**=' => '**',
            '&=' => '&', '|=' => '|', '^=' => '^',
            '<<=' => '<<', '>>=' => '>>',
            default => throw new RuntimeException("Unknown member assign op {$e->operator}"),
        };
        return "({$objCode}[{$key}] = {$objCode}[{$key}] {$op} {$val})";
    }

    private function emitNew(NewExpr $e): string
    {
        // Constructor functions return arrays (objects). Just call them directly.
        $callee = $this->emitExpr($e->callee);

        $hasSpread = false;
        foreach ($e->arguments as $a) {
            if ($a instanceof SpreadElement) { $hasSpread = true; break; }
        }

        if (!$hasSpread) {
            $args = array_map(fn(Expr $a) => $this->emitExpr($a), $e->arguments);
            return $callee . '(' . implode(', ', $args) . ')';
        }

        $merged = $this->emitSpreadArgs($e->arguments);
        return $callee . '(...' . $merged . ')';
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
        return "['__re' => true, 'pcre' => {$pcre}, 'g' => {$isGlobal}]";
    }

    // ───────────────── Function Calls ─────────────────

    private function emitCall(CallExpr $e): string
    {
        // Method call: obj.method(args)
        if ($e->callee instanceof MemberExpr && !$e->callee->computed && $e->callee->property instanceof Identifier) {
            return $this->emitMethodCall($e->callee, $e->arguments);
        }

        // Global function call
        $callee = $this->emitExpr($e->callee);
        // Wrap function expressions in parens for IIFE: (function(){...})(args)
        if ($e->callee instanceof FunctionExpr) {
            $callee = '(' . $callee . ')';
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
    private function emitMethodCall(MemberExpr $callee, array $args): string
    {
        $method = $callee->property->name;
        $obj = $this->emitExpr($callee->object);
        $emitArgs = fn() => array_map(fn(Expr $a) => $this->emitExpr($a), $args);

        // ── Math.* ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'Math') {
            $a = $emitArgs();
            return match ($method) {
                'floor' => '(int)floor(' . $a[0] . ')',
                'ceil' => '(int)ceil(' . $a[0] . ')',
                'abs' => 'abs(' . $a[0] . ')',
                'max' => 'max(' . $a[0] . ', ' . $a[1] . ')',
                'min' => 'min(' . $a[0] . ', ' . $a[1] . ')',
                'round' => '(int)round(' . $a[0] . ')',
                'random' => '(mt_rand() / mt_getrandmax())',
                default => "{$obj}['{$method}'](" . implode(', ', $a) . ")",
            };
        }

        // ── console.log ──
        if ($callee->object instanceof Identifier && $callee->object->name === 'console' && $method === 'log') {
            $a = $emitArgs();
            return '(($__out .= implode(" ", array_map("strval", [' . implode(', ', $a) . '])) . "\\n") ? null : null)';
        }

        $a = $emitArgs();
        $use = $this->makeUseClause();

        // ── Array methods ──
        return match ($method) {
            'push' => "({$obj}[] = {$a[0]})",
            'pop' => 'array_pop(' . $obj . ')',
            'shift' => 'array_shift(' . $obj . ')',
            'unshift' => 'array_unshift(' . $obj . ', ' . $a[0] . ')',
            'filter' => 'array_values(array_filter(' . $obj . ', ' . $a[0] . '))',
            'map' => 'array_map(' . $a[0] . ', ' . $obj . ')',
            'reduce' => 'array_reduce(' . $obj . ', ' . $a[0] . (isset($a[1]) ? ', ' . $a[1] : '') . ')',
            'forEach' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { ({$a[0]})(\$__v, \$__i); } })()",
            'every' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (!({$a[0]})(\$__v, \$__i)) return false; } return true; })()",
            'some' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return true; } return false; })()",
            'find' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__v; } return null; })()",
            'findIndex' => "(function(){$use} { foreach ({$obj} as \$__i => \$__v) { if (({$a[0]})(\$__v, \$__i)) return \$__i; } return -1; })()",
            'join' => 'implode(' . ($a[0] ?? "','") . ', ' . $obj . ')',
            'concat' => 'array_merge(' . $obj . ', ' . $a[0] . ')',
            'indexOf' => '(($__p = array_search(' . $a[0] . ', ' . $obj . ', true)) === false ? -1 : $__p)',
            'includes' => 'in_array(' . $a[0] . ', ' . $obj . ', true)',
            'slice' => $this->emitSlice($obj, $a),
            'sort' => "(function(){$use} { usort({$obj}, {$a[0]}); return {$obj}; })()",
            'reverse' => 'array_reverse(' . $obj . ')',
            'flat' => $this->emitFlat($obj, $a),
            'splice' => 'array_splice(' . $obj . ', ' . implode(', ', $a) . ')',
            'fill' => "(function(){$use} { for (\$__i = " . ($a[1] ?? '0') . "; \$__i < " . ($a[2] ?? 'count(' . $obj . ')') . "; \$__i++) { {$obj}[\$__i] = {$a[0]}; } return {$obj}; })()",

            // ── String methods ──
            'split' => $this->emitStrSplit($obj, $a),
            'toUpperCase' => 'strtoupper(' . $obj . ')',
            'toLowerCase' => 'strtolower(' . $obj . ')',
            'trim' => 'trim(' . $obj . ')',
            'trimStart' => 'ltrim(' . $obj . ')',
            'trimEnd' => 'rtrim(' . $obj . ')',
            'charAt' => 'substr(' . $obj . ', (int)' . $a[0] . ', 1)',
            'substring' => 'substr(' . $obj . ', ' . $a[0] . (isset($a[1]) ? ', ' . $a[1] . ' - ' . $a[0] : '') . ')',
            'startsWith' => 'str_starts_with(' . $obj . ', ' . $a[0] . ')',
            'endsWith' => 'str_ends_with(' . $obj . ', ' . $a[0] . ')',
            'repeat' => 'str_repeat(' . $obj . ', (int)' . $a[0] . ')',
            'replace' => $this->emitStrReplace($obj, $a, $args),
            'match' => $this->emitStrMatch($obj, $a, $args),
            'matchAll' => $this->emitStrMatchAll($obj, $a, $args),
            'search' => $this->emitStrSearch($obj, $a, $args),

            // ── Object methods ──
            'hasOwnProperty' => 'array_key_exists(' . $a[0] . ', ' . $obj . ')',

            // ── Unknown method → property access returning function ──
            default => "{$obj}['{$method}'](" . implode(', ', $a) . ")",
        };
    }

    // ───────────────── String method helpers ─────────────────

    private function emitSlice(string $obj, array $a): string
    {
        $start = $a[0];
        $len = isset($a[1]) ? "{$a[1]} - {$a[0]}" : null;
        $t = '$__t' . $this->tmpId++;
        $arrSlice = "array_slice({$t}, {$start}" . ($len !== null ? ", {$len}" : "") . ")";
        $strSlice = "substr({$t}, {$start}" . ($len !== null ? ", {$len}" : "") . ")";
        return "(is_string({$t} = {$obj}) ? {$strSlice} : {$arrSlice})";
    }

    private function emitStrSplit(string $obj, array $a): string
    {
        $sep = $a[0] ?? "''";
        $use = $this->makeUseClause();
        return "(function(){$use} { \$__s = {$sep}; \$__o = {$obj}; "
            . "if (is_array(\$__s) && (\$__s['__re'] ?? false)) return preg_split(\$__s['pcre'], \$__o); "
            . "if (\$__s === '') return str_split(\$__o); "
            . "return explode(\$__s, \$__o); })()";
    }

    private function emitStrReplace(string $obj, array $a, array $argExprs): string
    {
        if ($argExprs[0] instanceof RegexLiteral) {
            $pcre = $this->jsToPcre($argExprs[0]->pattern, $argExprs[0]->flags);
            $limit = str_contains($argExprs[0]->flags, 'g') ? '-1' : '1';
            return "preg_replace({$pcre}, {$a[1]}, {$obj}, {$limit})";
        }
        $use = $this->makeUseClause();
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

    // ───────────────── Closures / Functions ─────────────────

    private function emitClosure(?string $name, array $params, array $body, bool $isArrow = false, ?string $restParam = null): string
    {
        $isConstructor = !$isArrow && $this->bodyContainsThis($body);

        // Push new scope — include rest param in scope
        $allParams = $params;
        if ($restParam !== null) {
            $allParams[] = $restParam;
        }
        $this->pushScope($allParams);
        $hoisted = $this->collectVarDecls($body);
        foreach ($hoisted as $v) {
            $this->addVar($v);
        }
        if ($name !== null) {
            $this->addVar($name);
        }

        // Captured variables from parent scopes
        $captured = $this->getCapturedVars();
        // Also capture the function's own name for recursion
        if ($name !== null && !in_array($name, $captured)) {
            $captured[] = $name;
        }

        $useClause = '';
        if (!empty($captured)) {
            $refs = array_map(fn($v) => '&$' . $v, $captured);
            $useClause = ' use (' . implode(', ', $refs) . ')';
        }

        $paramList = array_map(fn($p) => '$' . $p, $params);
        if ($restParam !== null) {
            $paramList[] = '...$' . $restParam;
        }

        $prevConstructor = $this->inConstructor;
        $this->inConstructor = $isConstructor;

        $innerCode = '';
        if ($isConstructor) {
            $innerCode .= "\$__this = [];\n";
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

        $this->inConstructor = $prevConstructor;
        $this->popScope();

        return "function(" . implode(', ', $paramList) . ")" . $useClause . " {\n"
            . $this->indent($innerCode)
            . "}";
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
            } elseif ($stmt instanceof WhileStmt) {
                $vars = array_merge($vars, $this->collectVarDecls([$stmt->body]));
            } elseif ($stmt instanceof BlockStmt) {
                $vars = array_merge($vars, $this->collectVarDecls($stmt->statements));
            }
        }
        return array_unique($vars);
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
        $vars = $this->getAllScopeVars();
        if (empty($vars)) {
            return '';
        }
        $refs = array_map(fn($v) => '&$' . $v, $vars);
        return ' use (' . implode(', ', $refs) . ')';
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
        return "'" . addcslashes($s, "'\\") . "'";
    }

    private function indent(string $code): string
    {
        $lines = explode("\n", rtrim($code, "\n"));
        return implode("\n", array_map(fn($l) => $l === '' ? '' : '    ' . $l, $lines)) . "\n";
    }
}
