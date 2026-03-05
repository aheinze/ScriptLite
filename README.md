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

The optional `scriptlite` C extension replaces the PHP bytecode compiler and VM with a native implementation using computed-goto dispatch, tagged unions, and zero-copy string interning. The lexer and parser remain in PHP — the extension reads the PHP AST objects directly via the Zend API.

When the extension is loaded, `Engine` uses it transparently — no code changes required:

```php
$engine = new Engine();           // auto-detects extension
$engine = new Engine(true);       // force native (throws if unavailable)
$engine = new Engine(false);      // force PHP VM
```

For a dedicated step-by-step build/install guide, see [`ext/scriptlite/README.md`](ext/scriptlite/README.md).

### Building from source

Requires PHP 8.3+ development headers, `pcre2` dev library, and a C11 compiler.

```bash
cd ext/scriptlite
phpize
./configure
make -j$(nproc)
make test            # run .phpt tests
```

This produces `modules/scriptlite.so`. To load it:

```bash
# One-off test
php -dextension=/path/to/scriptlite.so your_script.php

# Persistent — add to your php.ini or a conf.d file
echo "extension=/path/to/scriptlite.so" | sudo tee /etc/php.d/50-scriptlite.ini
```

> The `.so` is tied to the PHP minor version it was built against (e.g. 8.3 vs 8.4). You must rebuild when switching PHP versions.

### Installing with PHP PIE

[PIE](https://github.com/php/pie) (PHP Installer for Extensions) can build and install from the repository:

```bash
# From a local checkout
pie install .

# Or directly from the repository
pie install vendor/scriptlite
```

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

### FrankenPHP

FrankenPHP uses static compilation. Add the extension to your build:

```bash
# Clone FrankenPHP and add scriptlite as a build extension
frankenphp build \
    --with-scriptlite=/path/to/ext/scriptlite

# Or in a Caddyfile-based setup, build a custom binary:
FRANKENPHP_BUILD_EXTENSIONS="scriptlite" \
    ./configure --with-scriptlite=/path/to/ext/scriptlite
```

Alternatively, if using the Docker image, follow the Docker instructions above with `FROM dunglas/frankenphp` as the base image.

### Laravel Sail / Docker Compose

Add the build step to your Sail Dockerfile:

```dockerfile
RUN apt-get update && apt-get install -y libpcre2-dev
COPY ext/scriptlite /tmp/scriptlite
RUN cd /tmp/scriptlite \
    && phpize && ./configure && make -j$(nproc) && make install \
    && docker-php-ext-enable scriptlite \
    && rm -rf /tmp/scriptlite
```

### Verifying the extension is loaded

```bash
php -m | grep scriptlite
# or
php -r "var_dump(extension_loaded('scriptlite'));"
```

In application code:

```php
if (extension_loaded('scriptlite')) {
    // Native backend available
}
```

## Architecture

```
ECMAScript source
  │
  ├── Lexer ──▶ Token stream
  │
  ├── Parser (Pratt) ──▶ AST
  │
  ├─┬── C Compiler ──▶ Bytecode ──▶ C VM (computed goto)    ← extension
  │ │
  │ ├── PHP Compiler ──▶ Bytecode ──▶ PHP VM (stack machine) ← fallback
  │ │
  │ └── PhpTranspiler ──▶ PHP source ──▶ eval (OPcache/JIT)
```

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
php vendor/bin/phpunit tests/
php -d extension=/path/to/ext/scriptlite/modules/scriptlite.so vendor/bin/phpunit tests/
```

Current suite coverage:

- 906 PHPUnit tests
- 2224 assertions in pure-PHP mode (VM + transpiler)
- 2229 assertions with the C extension loaded (native + transpiler)
- 31 PHPUnit test classes, plus standalone edge scripts in `tests/edge_*.php`

Without the extension loaded, native-only hardening tests are skipped by design.

## Benchmark

```bash
php bench.php                      # full benchmark (all backends)
php bench_compare.php              # C extension vs PHP VM side-by-side
```

Runs 10 workloads (fibonacci, sieve, quicksort, string ops, closures, objects/vectors, recursive tree, matrix multiplication, functional pipeline, regex) and compares execution modes:

### C extension vs PHP VM

| Benchmark | PHP VM | C Extension | Speedup |
|---|---|---|---|
| fibonacci(25) | 2203 ms | 16.1 ms | **137x** |
| sieve(5000) | 145 ms | 0.96 ms | **150x** |
| matrix(3x3x50) | 33.2 ms | 0.22 ms | **150x** |
| closures(5000) | 72.6 ms | 0.52 ms | **139x** |
| pipeline(500) | 14.2 ms | 0.13 ms | **109x** |
| tree(depth=10) | 74.1 ms | 0.79 ms | **94x** |
| quicksort(200) | 48.2 ms | 0.63 ms | **76x** |
| string ops | 3.7 ms | 0.11 ms | **32x** |
| regex(200iter) | 6.0 ms | 0.57 ms | **11x** |
| **Total** | **2625 ms** | **20.3 ms** | **~130x** |

### All backends

| Mode | Execution time | vs Native PHP |
|---|---|---|
| PHP VM (bytecode interpreter) | ~77 ms | ~104x |
| Transpiled PHP (eval'd) | ~2.6 ms | ~3.5x |
| C extension (computed goto VM) | ~0.7 ms | ~1x |
| Native PHP (hand-written) | ~0.74 ms | 1x |


## License

MIT
