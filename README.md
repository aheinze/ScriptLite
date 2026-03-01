# ScriptLite

An ECMAScript interpreter written in PHP 8.3+. Parses and executes a useful subset of ES2015+ — enough for algorithms, data processing, closures, constructors, regex, and more.

Two execution backends:
- **Bytecode VM** — a stack-based virtual machine with 55 opcodes and register file optimization
- **PHP transpiler** — compiles ECMAScript to PHP source that OPcache/JIT can optimize natively (~36x faster than the VM)

## Quick start

```bash
composer install
```

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

**Operators:** arithmetic (`+` `-` `*` `/` `%` `**`), increment/decrement (`++` `--`, prefix and postfix), comparison (`==` `!=` `===` `!==` `<` `<=` `>` `>=`), logical (`&&` `||` `!`), bitwise (`&` `|` `^` `~` `<<` `>>` `>>>`), nullish coalescing (`??`), ternary (`? :`), optional chaining (`?.`), typeof, void, delete, in, instanceof, assignment (`=` `+=` `-=` `*=` `/=` `%=` `**=` `??=` `&=` `|=` `^=` `<<=` `>>=` `>>>=`)

**Control flow:** `if`/`else`, `while`, `for`, `do...while`, `switch`/`case`/`default`, `break`, `continue`, `return`

**Error handling:** `try`/`catch`, `throw`

**Variables:** `var` (function-scoped, hoisted), `let` (block-scoped), `const` (block-scoped, immutable)

**Functions:** declarations, expressions, arrow functions (`=>` with expression and block bodies), closures with lexical scoping, recursion, `new` / constructors / `this`, rest parameters, spread syntax

**Template literals:** `` `hello ${name}` `` with expression interpolation and nesting

**Built-ins:**
- `console.log()`
- `Math.floor`, `Math.ceil`, `Math.abs`, `Math.max`, `Math.min`, `Math.round`, `Math.random`, `Math.PI`
- `Number()`, `Number.isInteger()`, `Number.isFinite()`, `Number.isNaN()`, `Number.parseInt()`, `Number.parseFloat()`
- `String()`, `String.fromCharCode()`
- `parseInt()`, `parseFloat()`, `isNaN()`, `isFinite()`
- `Date`, `Date.now()`

**Array methods:** `push`, `pop`, `shift`, `unshift`, `map`, `filter`, `reduce`, `forEach`, `every`, `some`, `find`, `findIndex`, `indexOf`, `includes`, `join`, `concat`, `slice`, `splice`, `sort`, `reverse`, `flat`, `fill`

**String methods:** `split`, `toUpperCase`, `toLowerCase`, `trim`, `trimStart`, `trimEnd`, `charAt`, `substring`, `startsWith`, `endsWith`, `repeat`, `replace`, `match`, `matchAll`, `search`, `indexOf`, `includes`, `slice`, `padStart`, `padEnd`

**Object methods:** `hasOwnProperty`, `Object.keys`, `Object.values`, `Object.entries`, `Object.assign`

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

The same globals work with the transpiler. Pass the globals at both transpile time (so the scope tracker knows the variable names) and at execution time (to provide the values):

```php
$globals = ['acc' => $acc, 'multiplier' => 2];

$php = $engine->transpile($script, $globals);
$result = $engine->evalTranspiled($php, $globals);

// Or for long-running workers (no eval memory leak):
$result = $engine->runTranspiled($php, $globals);

// Or save to a file for OPcache:
$engine->saveTranspiled($php, '/tmp/script.php');
$__globals = $globals;
$result = include '/tmp/script.php';
```

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
| `src/Transpiler/` | AST → PHP source code transpiler |

### VM opcodes

The VM uses 55 int-backed enum opcodes organized by category: stack ops, arithmetic, comparison, bitwise, variables (including register-optimized `GetReg`/`SetReg`), control flow, functions, exception handling, scope, and property access. The `match()` on int-backed enums compiles to a jump table under OPcache/JIT.

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

533 PHPUnit tests across 27 test files covering arithmetic, arrays, arrow functions, break/continue, constructors, control flow, do-while, functions, globals, number/string objects, objects, operators, regex, scoping, string methods, switch, template literals, try/catch, spread/rest, extended operators (increment/decrement, exponentiation, bitwise, void, delete, in, instanceof), and edge cases.

## Benchmark

```bash
php bench.php
```

Runs 10 workloads (sieve of Eratosthenes, fibonacci with memoization, quicksort, matrix multiplication, string/regex processing, functional pipeline, constructors, CSV parsing, histogram, recursive tree) and compares execution modes:

| Mode | Execution time | vs Native PHP |
|---|---|---|
| VM (bytecode interpreter) | ~76 ms | ~98x |
| Transpiled PHP (eval'd) | ~2 ms | ~2.7x |
| Native PHP (hand-written) | ~0.8 ms | 1x |

The register file optimization reduces VM variable access overhead by ~13x. The transpiler closes the gap further to 2.7x by letting OPcache/JIT compile the generated PHP natively.

## License

MIT
