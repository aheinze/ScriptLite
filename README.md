# ScriptLite

A sandboxed interpreter for a subset of ECMAScript, written in PHP. Embed user-provided scripts in your PHP application for data processing, configuration logic, computed fields, template expressions, workflow rules, and more — without giving scripts access to the filesystem, network, database, or any PHP internals. It covers the most useful parts of the language (variables, functions, closures, arrays, objects, regex, error handling, destructuring, etc.) while intentionally omitting modules, classes, async/await, generators, and other heavy runtime features.

Scripts run in a sealed environment: they can only use the ECMAScript built-ins listed below and any globals you explicitly pass in. There is no `require`, no `eval`, no `process`, no `globalThis` — just pure computation on the data you provide.

### Use cases

- **User-defined formulas** — let users write `price * quantity * (1 - discount)` in a CMS or spreadsheet-like app
- **Configuration logic** — express feature flags, A/B rules, or pricing tiers as scripts instead of hardcoded PHP
- **Data transformation** — map, filter, and reshape API payloads or database rows with user-supplied logic
- **Computed fields** — derive values in a form builder or report engine using expressions like `items.reduce((s, i) => s + i.total, 0)`
- **Workflow / automation rules** — evaluate conditions and actions defined by end users at runtime
- **Template expressions** — safely evaluate interpolated expressions in user-generated content

### Execution backends

- **Bytecode VM** — a stack-based virtual machine with 62 opcodes and register file optimization
- **PHP transpiler** — compiles ECMAScript to PHP source that OPcache/JIT can optimize natively (~31x faster than the VM)

## Quick start

```php
use ScriptLite\Engine;

$engine = new Engine();

// Evaluate and get the result
$result = $engine->eval('
    function fib(n) {
        if (n <= 1) return n;
        return fib(n - 1) + fib(n - 2);
    }
    fib(10);
');
// $result === 55

// Or compile once, run many times
$compiled = $engine->compile($script);
$result = $engine->run($compiled);

// Or transpile to PHP for maximum performance
$php = $engine->transpile($script);
$result = $engine->evalTranspiled($php);
```

## Language support

**Types:** numbers (int/float), strings, booleans, null, undefined, arrays, objects, regex, Date

**Operators:** arithmetic (`+` `-` `*` `/` `%` `**`), increment/decrement (`++` `--`, prefix and postfix), comparison (`==` `!=` `===` `!==` `<` `<=` `>` `>=`), logical (`&&` `||` `!`), bitwise (`&` `|` `^` `~` `<<` `>>` `>>>`), nullish coalescing (`??`), ternary (`? :`), optional chaining (`?.`, `?.[]`, `?.()`), typeof, void, delete, in, instanceof, assignment (`=` `+=` `-=` `*=` `/=` `%=` `**=` `??=` `&=` `|=` `^=` `<<=` `>>=` `>>>=`)

**Control flow:** `if`/`else`, `while`, `for` (including multi-var init: `for (let i = 0, j = 10; ...)`), `for...of`, `for...in`, `do...while`, `switch`/`case`/`default`, `break`, `continue`, `return`, comma operator in for-updates

**Error handling:** `try`/`catch`/`finally`, `throw`, optional catch binding (`catch { }`)

**Variables:** `var` (function-scoped, hoisted), `let` (block-scoped), `const` (block-scoped, immutable), array destructuring (`var [a, b, ...rest] = arr`), object destructuring (`var {name, age: a} = obj`) with defaults, nested destructuring (`var {user: {name, age}} = obj`, `var [a, [b, c]] = arr`), destructuring in function parameters (`function f({x, y}) {}`)

**Functions:** declarations, expressions, arrow functions (`=>` with expression and block bodies), closures with lexical scoping, recursion, `new` / constructors / `this`, rest parameters, spread syntax, default parameters, destructuring parameters with nesting and defaults

**Object literals:** shorthand properties (`{x, y}`), computed property names (`{[expr]: value}`)

**Template literals:** `` `hello ${name}` `` with expression interpolation and nesting

**String escapes:** `\n`, `\t`, `\r`, `\\`, `\0`, `\uXXXX`, `\u{XXXXX}`, `\xXX`

**Built-ins:**
- `console.log()`
- `Math.floor`, `Math.ceil`, `Math.abs`, `Math.max`, `Math.min`, `Math.round`, `Math.random`, `Math.PI`, `Math.E`, `Math.sqrt`, `Math.pow`, `Math.sin`, `Math.cos`, `Math.tan`, `Math.asin`, `Math.acos`, `Math.atan`, `Math.atan2`, `Math.log`, `Math.log2`, `Math.log10`, `Math.exp`, `Math.cbrt`, `Math.hypot`, `Math.sign`, `Math.trunc`, `Math.clz32`, `Math.LN2`, `Math.LN10`, `Math.LOG2E`, `Math.LOG10E`, `Math.SQRT1_2`, `Math.SQRT2`
- `Number()`, `Number.isInteger()`, `Number.isFinite()`, `Number.isNaN()`, `Number.parseInt()`, `Number.parseFloat()`
- `String()`, `String.fromCharCode()`
- `parseInt()`, `parseFloat()`, `isNaN()`, `isFinite()`, `encodeURIComponent()`, `decodeURIComponent()`, `encodeURI()`, `decodeURI()`
- `NaN`, `Infinity`, `undefined`
- `Date`, `Date.now()`
- `JSON.stringify()`, `JSON.parse()`

**Number methods:** `toFixed`, `toPrecision`, `toExponential`, `toString` (with radix)

**Array methods:** `push`, `pop`, `shift`, `unshift`, `map`, `filter`, `reduce`, `reduceRight`, `forEach`, `every`, `some`, `find`, `findIndex`, `findLast`, `findLastIndex`, `indexOf`, `includes`, `join`, `concat`, `slice`, `splice`, `sort`, `reverse`, `flat`, `flatMap`, `fill`, `at`

**String methods:** `split`, `toUpperCase`, `toLowerCase`, `trim`, `trimStart`, `trimEnd`, `charAt`, `substring`, `startsWith`, `endsWith`, `repeat`, `replace` (with string or callback), `replaceAll`, `match`, `matchAll`, `search`, `indexOf`, `includes`, `slice`, `padStart`, `padEnd`, `at`

**Object methods:** `hasOwnProperty`, `Object.keys`, `Object.values`, `Object.entries`, `Object.assign`, `Object.is`, `Object.create`, `Object.freeze`

**Regex:** literals (`/pattern/flags`), `RegExp` constructor, `test()`, `exec()`, flags `g` `i` `m`

## PHP interop

Pass PHP variables into the script via the second argument to `eval()`, `run()`, or `evalTranspiled()`. Results are automatically converted back to PHP types.

### Primitives, arrays, and closures

```php
$engine = new Engine();

// Scalars are passed through directly
$result = $engine->eval('name + " is " + age', [
    'name' => 'Alice',
    'age' => 30,
]);
// $result === "Alice is 30"

// PHP indexed arrays become arrays
$result = $engine->eval('items.map(x => x * 2)', [
    'items' => [1, 2, 3],
]);
// $result === [2, 4, 6]

// PHP associative arrays become objects
$result = $engine->eval('config.host + ":" + config.port', [
    'config' => ['host' => 'localhost', 'port' => 3000],
]);
// $result === "localhost:3000"

// PHP closures become callable functions
$result = $engine->eval('transform("hello")', [
    'transform' => fn(string $s) => strtoupper($s),
]);
// $result === "HELLO"
```

### PHP object instances

PHP objects are automatically wrapped so scripts can read/write properties and call methods. Method arguments are auto-coerced to match PHP type hints (ECMAScript numbers are floats, but PHP methods may expect `int`, `string`, etc.).

```php
class Account {
    public function __construct(
        public string $owner,
        public float $balance,
    ) {}

    public function deposit(float $amount): float {
        $this->balance += $amount;
        return $this->balance;
    }

    public function withdraw(float $amount): float {
        $this->balance -= $amount;
        return $this->balance;
    }
}

$acc = new Account('Alice', 1000);

$engine->eval('
    acc.deposit(250);
    acc.withdraw(75);
', ['acc' => $acc]);

// The original PHP object is mutated:
// $acc->balance === 1175.0
```

Objects returned from methods are also wrapped, so chained access works. PHP closures and arrays returned from methods are converted to their ECMAScript equivalents.

### Return value conversion

| ECMAScript type | PHP type |
|---|---|
| number (int) | `int` |
| number (float) | `float` |
| string | `string` |
| boolean | `bool` |
| null | `null` |
| undefined | `null` |
| array | `array` (indexed) |
| object | `array` (associative) |

### Transpiler path

The same globals work with the transpiler. The transpile step only needs variable **names** (so the scope tracker captures them correctly); actual values are provided at execution time:

```php
// One-shot: transpile and execute in a single call
$result = $engine->transpileAndEval($script, ['acc' => $acc, 'multiplier' => 2]);

// Transpile once, run many times with different values
$callback = $engine->getTranspiledCallback($script, ['acc', 'multiplier']);
$result = $callback(['acc' => $acc1, 'multiplier' => 2]);
$result = $callback(['acc' => $acc2, 'multiplier' => 3]);

// Or step by step for full control:
$php = $engine->transpile($script, ['acc', 'multiplier']);
$result = $engine->runTranspiled($php, ['acc' => $acc]);    // temp file (worker-safe)
$result = $engine->evalTranspiled($php, ['acc' => $acc]);   // eval (leaks in long-running workers)

// Or save to a file for OPcache:
$engine->saveTranspiled($php, '/tmp/script.php');
$__globals = ['acc' => $acc, 'multiplier' => 2];
$result = include '/tmp/script.php';
```

## Security model

Scripts execute in a fully sandboxed environment:

- **No filesystem access** — no `require`, `import`, `fs`, or file I/O of any kind
- **No network access** — no `fetch`, `XMLHttpRequest`, or sockets
- **No PHP internals** — no `eval`, `exec`, `system`, or access to PHP's global scope
- **No ambient globals** — no `process`, `globalThis`, `window`, or `document`
- **Explicit data boundary** — scripts can only see globals you pass in via the `$globals` parameter
- **Pure computation** — the only side effects are mutations to objects/arrays you explicitly provide

The attack surface is limited to CPU and memory consumption. For untrusted input, combine with PHP's `set_time_limit()` / `memory_limit` to cap resource usage.

## Architecture

```
ECMAScript source
  │
  ├── Lexer ──▶ Token stream
  │
  ├── Parser (Pratt) ──▶ AST
  │
  ├─┬── Compiler ──▶ Bytecode ──▶ VM (stack machine)
  │ │
  │ └── PhpTranspiler ──▶ PHP source ──▶ eval (OPcache/JIT)
```

| Directory | Purpose |
|---|---|
| `src/Lexer/` | Zero-copy tokenizer, regex literal support |
| `src/Ast/` | AST node types + Pratt parser |
| `src/Compiler/` | Single-pass AST → bytecode compiler |
| `src/Vm/` | Stack-based bytecode VM |
| `src/Runtime/` | Runtime objects (JsArray, JsObject, JsClosure, JsRegex, JsDate, Environment) |
| `src/Transpiler/` | AST → PHP source code transpiler with type inference |

### VM opcodes

The VM uses 62 int-backed enum opcodes organized by category: stack ops, arithmetic, comparison, bitwise, variables (including register-optimized `GetReg`/`SetReg`), control flow, functions, exception handling, scope, and property access. The `match()` on int-backed enums compiles to a jump table under OPcache/JIT.

Non-captured local variables (`var` declarations and parameters) are allocated to an integer-indexed register file at compile time, bypassing the Environment hash table for ~13x faster variable access on hot paths. Variables captured by inner closures remain in the Environment scope chain to preserve correct closure semantics.

### Transpiler

The transpiler maps ECMAScript constructs directly to PHP equivalents:
- Objects → PHP associative arrays
- Arrays → PHP indexed arrays
- Functions → PHP closures with `use (&$captured)` for scope capture
- Constructors → closures that build and return arrays
- Methods → inlined PHP built-in calls (`array_map`, `explode`, `preg_replace`, etc.)

## Tests

```bash
php vendor/bin/phpunit tests/
```

783 PHPUnit tests (1747 assertions) across 34 test files covering arithmetic, arrays, arrow functions, break/continue, constructors, control flow, destructuring (including nested and function params), do-while, for...of/for...in, functions, globals, JSON, number/string objects, objects, operators, optional chaining, regex, scoping, string methods (including replace with callbacks), switch, template literals, try/catch/finally, spread/rest, extended operators (increment/decrement, exponentiation, bitwise, void, delete, in, instanceof), comma operator, reduceRight, fuzzing, and edge cases.

## Benchmark

```bash
php bench.php
```

Runs 10 workloads (sieve of Eratosthenes, fibonacci with memoization, quicksort, matrix multiplication, string/regex processing, functional pipeline, constructors, CSV parsing, histogram, recursive tree) and compares execution modes:

| Mode | Execution time | vs Native PHP |
|---|---|---|
| VM (bytecode interpreter) | ~80 ms | ~108x |
| Transpiled PHP (eval'd) | ~2.6 ms | ~3.5x |
| Native PHP (hand-written) | ~0.75 ms | 1x |


## License

MIT
