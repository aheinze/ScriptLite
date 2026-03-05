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

- **C extension** — native bytecode VM with computed-goto dispatch (~130x faster than the PHP VM, ~3.5x faster than the transpiler)
- **Bytecode VM** — a stack-based virtual machine with 62 opcodes and register file optimization
- **PHP transpiler** — compiles ECMAScript to PHP source that OPcache/JIT can optimize natively (~31x faster than the PHP VM)

## Installation

```bash
composer require aheinze/scriptlite
```

Requires **PHP 8.3+**. No external dependencies for the pure-PHP backends.

For the optional C extension (130x faster VM), see [C extension](#c-extension) below.

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

## Caching

The `Engine` instance maintains LRU caches at every stage of the pipeline. Reuse the same instance for best performance:

```php
$engine = new Engine();

// Repeated eval() calls with the same source skip recompilation
$engine->eval($script, ['x' => 1]);
$engine->eval($script, ['x' => 2]); // bytecode served from cache

// transpile() and runTranspiled() also cache automatically
$php = $engine->transpile($script, ['x']); // cached after first call
$engine->runTranspiled($php, ['x' => 1]); // temp file reused + OPcache'd
$engine->runTranspiled($php, ['x' => 2]); // same cached file
```

| Cache layer | Scope | Max entries |
|---|---|---|
| Parse (AST) | Shared by `compile()` and `transpile()` | 12 |
| Compiled bytecode | `eval()` | 32 |
| Transpiled PHP source | `transpile()` | 32 |
| Transpiled temp files | `runTranspiled()` | 16 |

Temp files are written to `sys_get_temp_dir()` and precompiled with `opcache_compile_file()` when available. The file cache evicts the least-recently-used entry and cleans up the corresponding file on disk.

## Security model

Scripts execute in a fully sandboxed environment:

- **No filesystem access** — no `require`, `import`, `fs`, or file I/O of any kind
- **No network access** — no `fetch`, `XMLHttpRequest`, or sockets
- **No PHP internals** — no `eval`, `exec`, `system`, or access to PHP's global scope
- **No ambient globals** — no `process`, `globalThis`, `window`, or `document`
- **Explicit data boundary** — scripts can only see globals you pass in via the `$globals` parameter
- **Pure computation** — the only side effects are mutations to objects/arrays you explicitly provide

The attack surface is limited to CPU and memory consumption. For untrusted input, combine with PHP's `set_time_limit()` / `memory_limit` to cap resource usage.

## C extension

The optional `scriptlite` C extension replaces the PHP bytecode compiler and VM with a native implementation using computed-goto dispatch, tagged unions, and zero-copy string interning. The extension embeds the parser runtime, so it works standalone without the Composer autoloader — only the `.so` file is needed.

When the extension is loaded, `Engine` delegates to `ScriptLiteExt\Engine` transparently — no code changes required:

```php
$engine = new Engine();      // uses ScriptLiteExt\Engine when available
$engine = new Engine(true);  // same as default
$engine = new Engine(false); // force PHP VM/transpiler, ignore extension
```

The extension registers its classes under the `ScriptLiteExt\` namespace (`Engine`, `Compiler`, `VirtualMachine`, `CompiledScript`) to avoid conflicts with the userland `ScriptLite\` namespace. Legacy `ScriptLiteNative\` aliases are provided for backward compatibility.

### Building from source

Requires PHP 8.3+ development headers (`php-dev` / `php-devel`), `libpcre2-dev`, and a C11 compiler.

```bash
# Using composer script
composer build:ext

# Or manually
cd ext/scriptlite
phpize
./configure --enable-scriptlite
make -j$(nproc)
make test            # run .phpt tests
```

This produces `modules/scriptlite.so`. To load it:

```bash
# One-off
php -dextension=$(pwd)/ext/scriptlite/modules/scriptlite.so your_script.php

# Persistent — add to your php.ini or a conf.d file
echo "extension=/path/to/scriptlite.so" | sudo tee /etc/php.d/50-scriptlite.ini
```

> The `.so` is tied to the PHP minor version it was built against (e.g. 8.3 vs 8.4). Rebuild when switching PHP versions.

### Docker

Add the build to your Dockerfile:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y libpcre2-dev

COPY ext/scriptlite /tmp/scriptlite
RUN cd /tmp/scriptlite \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable scriptlite \
    && rm -rf /tmp/scriptlite
```

### Verifying the extension is loaded

```bash
php -m | grep scriptlite
# or
php -r "var_dump(class_exists('ScriptLiteExt\Engine', false));"
```

## Architecture

```
ECMAScript source
  │
  ├── Lexer ──▶ Token stream
  │
  ├── Parser (Pratt) ──▶ AST
  │
  ├─┬── C Compiler ──▶ Bytecode ──▶ C VM (computed goto)      ← extension (standalone)
  │ │
  │ ├── PHP Compiler ──▶ Bytecode ──▶ PHP VM (stack machine)   ← pure PHP fallback
  │ │
  │ └── PhpTranspiler ──▶ PHP source ──▶ eval (OPcache/JIT)   ← fastest pure PHP
```

The C extension embeds the parser runtime, so when loaded it handles the full pipeline (lex → parse → compile → execute) without the Composer autoloader. The userland `Engine` class delegates to `ScriptLiteExt\Engine` when available.

| Directory | Purpose |
|---|---|
| `src/Lexer/` | Zero-copy tokenizer, regex literal support |
| `src/Ast/` | AST node types + Pratt parser |
| `src/Compiler/` | Single-pass AST → bytecode compiler (PHP) |
| `src/Vm/` | Stack-based bytecode VM (PHP) |
| `src/Runtime/` | Runtime objects (JsArray, JsObject, JsClosure, JsRegex, JsDate, Environment) |
| `src/Transpiler/` | AST → PHP source code transpiler with type inference |
| `ext/scriptlite/` | Native C compiler + VM (optional extension) |

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
composer test           # all phases (PHP-only + extension + .phpt)
composer test:php       # PHPUnit without extension
composer test:ext       # PHPUnit with C extension loaded
```

`composer test` runs `run-tests.php` which executes three phases:
1. PHPUnit in pure PHP-library mode (no extension)
2. PHPUnit with the C extension loaded
3. Extension `.phpt` tests in `ext/scriptlite/tests`

907 tests, ~2230 assertions across all three backends. Extension-gated tests are skipped when the `.so` is not loaded.

## Benchmark

```bash
composer bench          # without extension
composer bench:ext      # with C extension
```

Runs 10 workloads (fibonacci, sieve, quicksort, string ops, closures, objects/vectors, recursive tree, matrix multiplication, functional pipeline, regex).

### C extension vs PHP VM vs Transpiler

| Benchmark | PHP VM | Transpiler | C Extension | C/VM | C/Tr |
|---|---|---|---|---|---|
| fibonacci(25) | 2231 ms | 54 ms | 16.8 ms | **133x** | 3.2x |
| sieve(5000) | 145 ms | 1.5 ms | 0.94 ms | **154x** | 1.6x |
| matrix(3x3x50) | 33.3 ms | 0.27 ms | 0.23 ms | **146x** | 1.2x |
| closures(5k) | 73.1 ms | 1.9 ms | 0.54 ms | **135x** | 3.6x |
| pipeline(500) | 14.3 ms | 0.81 ms | 0.13 ms | **110x** | 6.2x |
| tree(depth=10) | 73.8 ms | 2.9 ms | 0.81 ms | **91x** | 3.6x |
| quicksort(200) | 48.1 ms | 1.8 ms | 0.56 ms | **86x** | 3.3x |
| objects+vectors | 25.3 ms | 2.1 ms | 0.31 ms | **82x** | 6.7x |
| string ops | 3.7 ms | 0.15 ms | 0.12 ms | **31x** | 1.3x |
| regex(200iter) | 6.0 ms | 0.77 ms | 0.57 ms | **11x** | 1.3x |
| **Total** | **2654 ms** | **67 ms** | **21 ms** | **126x** | **3.2x** |

### Execution modes (combined workload)

| Mode | Time | vs Native PHP |
|---|---|---|
| PHP VM (compile + execute) | ~77 ms | ~100x |
| PHP VM (pre-compiled) | ~76 ms | ~99x |
| PHP VM (unserialize + execute) | ~76 ms | ~99x |
| Transpiler (eval) | ~2.6 ms | ~3.4x |
| Transpiler (cached file) | ~2.6 ms | ~3.4x |
| Native PHP (same algorithms) | ~0.76 ms | 1x |

Memory per run: VM 596 KB, Transpiler 271 KB, Native PHP 171 KB


## License

MIT
