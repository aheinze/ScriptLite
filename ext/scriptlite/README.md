# ScriptLite C Extension

> **Mirror repository** — this repo is automatically mirrored from [aheinze/ScriptLite](https://github.com/aheinze/ScriptLite) for PHP PIE installation. All development, issues, and pull requests happen in the [main repository](https://github.com/aheinze/ScriptLite).

High-performance PHP extension for the [ScriptLite](https://github.com/aheinze/ScriptLite) ECMAScript interpreter. Replaces the PHP bytecode VM with a C implementation using computed-goto dispatch, delivering ~120x speedup.

The extension embeds the parser runtime — no Composer autoloader or PHP library required at runtime.

## Installation

### Via PHP PIE (recommended)

```bash
pie install aheinze/scriptlite-ext
```

### Manual build

Prerequisites: PHP 8.3+ dev headers, `phpize`, C11 compiler, `libpcre2-dev`, `make`.

```bash
phpize
./configure --enable-scriptlite
make -j"$(nproc)"
make install
```

Then enable:

```ini
extension=scriptlite
```

## Verify

```bash
php -r "var_dump(extension_loaded('scriptlite'));"
# bool(true)

php -r "echo (new ScriptLiteExt\Engine())->eval('1 + 2');"
# 3
```

## Usage

### Standalone (no PHP library needed)

```php
$engine = new ScriptLiteExt\Engine();
$result = $engine->eval('Math.max(1, 2, 3)');
echo $result; // 3
```

### With ScriptLite PHP library

When installed alongside `aheinze/ScriptLite`, the PHP `Engine` class automatically delegates to the C extension — no code changes needed.

```php
$engine = new ScriptLite\Engine();
$result = $engine->eval('Math.max(1, 2, 3)'); // uses C extension transparently
```

## API

### `ScriptLiteExt\Engine`

| Method | Description |
|--------|-------------|
| `eval(string $code, array $globals = []): mixed` | Evaluate JS code and return the result |
| `compile(string $code): CompiledScript` | Compile JS to bytecode |
| `run(CompiledScript $script, array $globals = []): mixed` | Execute compiled bytecode |
| `transpile(string $code): string` | Transpile JS to PHP code |
| `getOutput(): string` | Get captured `console.log` output |

### `ScriptLiteExt\Compiler`

| Method | Description |
|--------|-------------|
| `compile(Program $ast): CompiledScript` | Compile an AST to bytecode |

### `ScriptLiteExt\VirtualMachine`

| Method | Description |
|--------|-------------|
| `execute(CompiledScript $script, array $globals = []): mixed` | Execute compiled bytecode |
| `getOutput(): string` | Get captured `console.log` output |

## License

MIT
