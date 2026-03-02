<?php

declare(strict_types=1);

namespace ScriptLite;

use ScriptLite\Ast\Parser;
use ScriptLite\Compiler\Compiler;
use ScriptLite\Compiler\CompiledScript;
use ScriptLite\Runtime\Environment;
use ScriptLite\Runtime\PhpObjectProxy;
use ScriptLite\Transpiler\PhpTranspiler;
use ScriptLite\Transpiler\Runtime\JSFunction as TrJsFunction;
use ScriptLite\Transpiler\Runtime\JSObject as TrJsObject;
use ScriptLite\Vm\VirtualMachine;

/**
 * High-level facade for embedding the JS engine in PHP applications.
 *
 * Usage:
 *   $engine = new Engine();
 *   $result = $engine->eval('1 + 2');  // 3
 *   echo $engine->getOutput();         // console.log output
 *
 * With globals:
 *   $result = $engine->eval('name + " is " + age', [
 *       'name' => 'Alice',
 *       'age' => 30,
 *   ]);
 *
 * For repeated execution of the same script, compile once and run many:
 *   $compiled = $engine->compile('function greet(n) { return "hi " + n; }; greet(name);');
 *   $result = $engine->run($compiled, ['name' => 'Alice']);
 */
final class Engine
{
    private ?VirtualMachine $lastVm = null;

    /**
     * Parse and compile JS source to bytecode.
     */
    public function compile(string $source): CompiledScript
    {
        $parser   = new Parser($source);
        $program  = $parser->parse();
        $compiler = new Compiler();
        return $compiler->compile($program);
    }

    /**
     * Execute a compiled script.
     *
     * @param array<string, mixed> $globals PHP values injected as JS globals
     */
    public function run(CompiledScript $script, array $globals = []): mixed
    {
        $vm = new VirtualMachine();
        $env = null;
        if (!empty($globals)) {
            $env = $vm->createGlobalEnvironmentWithVars($globals);
        }
        $result = $vm->execute($script, $env);
        $this->lastVm = $vm;
        return VirtualMachine::toPhp($result);
    }

    /**
     * Compile and execute JS source in one call.
     *
     * @param array<string, mixed> $globals PHP values injected as JS globals
     */
    public function eval(string $source, array $globals = []): mixed
    {
        return $this->run($this->compile($source), $globals);
    }

    /**
     * Transpile JS source to PHP source code.
     *
     * Pass globals to register their names in the scope tracker so inner
     * closures capture them correctly. Only the keys matter at transpile time;
     * actual values are provided at execution time.
     *
     * @param array<string, mixed> $globals Keys = variable names to register
     */
    public function transpile(string $source, array $globals = []): string
    {
        $parser  = new Parser($source);
        $program = $parser->parse();
        $transpiler = new PhpTranspiler();
        return $transpiler->transpile($program, array_keys($globals));
    }

    /**
     * Transpile JS source and return a closure for repeated execution.
     *
     * @param array<string, mixed> $globals Keys = variable names to register for transpilation
     */
    public function getTranspiledCallback(string $source, array $globals = []): \Closure
    {
        $phpSource = $this->transpile($source, $globals);
        return function (array $runtimeGlobals = []) use ($phpSource) {
            return $this->runTranspiled($phpSource, $runtimeGlobals);
        };
    }

    /**
     * Transpile and execute JS source in one call via eval().
     *
     * Convenience method that combines transpile() + evalTranspiled().
     *
     * @param array<string, mixed> $globals PHP values injected as JS globals
     */
    public function transpileAndEval(string $source, array $globals = [], array $opts = []): mixed
    {

        $opts = array_merge([
            'use_eval' => false, // if false, uses runTranspiled() to avoid eval() memory leak
        ], $opts);

        $php = $this->transpile($source, $globals);

        if ($opts['use_eval']) {
            return $this->evalTranspiled($php, $globals);
        }

        return $this->runTranspiled($php, $globals);
    }

    /**
     * Execute transpiled PHP source via eval().
     *
     * Warning: leaks memory in long-running workers (FrankenPHP, Swoole, RoadRunner)
     * because eval'd code is never freed by OPcache. Use runTranspiled() instead.
     *
     * @param array<string, mixed> $globals PHP values available as JS globals
     */
    public function evalTranspiled(string $phpSource, array $globals = []): mixed
    {
        $__globals = self::normalizeGlobals($globals);
        set_error_handler(static function (int $errno, string $msg): bool {
            if (str_contains($msg, 'Undefined variable')) {
                preg_match('/\$(\w+)/', $msg, $m);
                throw new \RuntimeException(($m[1] ?? '?') . ' is not defined');
            }
            return false; // let other warnings propagate normally
        }, E_WARNING);
        try {
            return self::denormalizeValue(eval($phpSource));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Execute transpiled PHP source via a temporary file.
     *
     * Writes the PHP source to a temp file, includes it, and deletes it.
     * Safe for long-running workers — no eval() memory leak.
     *
     * @param array<string, mixed> $globals PHP values available as JS globals
     */
    public function runTranspiled(string $phpSource, array $globals = []): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'scriptlite_') . '.php';
        file_put_contents($file, "<?php\n" . $phpSource);
        set_error_handler(static function (int $errno, string $msg): bool {
            if (str_contains($msg, 'Undefined variable')) {
                preg_match('/\$(\w+)/', $msg, $m);
                throw new \RuntimeException(($m[1] ?? '?') . ' is not defined');
            }
            return false; // let other warnings propagate normally
        }, E_WARNING);
        try {
            $__globals = self::normalizeGlobals($globals);
            return self::denormalizeValue(include $file);
        } finally {
            restore_error_handler();
            @unlink($file);
        }
    }

    /**
     * Save transpiled PHP source to a file for repeated inclusion.
     *
     * The file can be require'd directly and benefits from OPcache.
     * Set $__globals before including to inject variables:
     *
     *   $__globals = ['name' => 'Alice'];
     *   $result = include '/path/to/script.php';
     */
    public function saveTranspiled(string $phpSource, string $path): string
    {
        file_put_contents($path, "<?php\n" . $phpSource);
        if (function_exists('opcache_compile_file')) {
            @opcache_compile_file($path);
        }
        return $path;
    }

    /**
     * Get console output from the last execution.
     */
    public function getOutput(): string
    {
        return $this->lastVm?->getOutput() ?? '';
    }

    /**
     * Recursively convert PHP objects to associative arrays for the transpiler path.
     *
     * The transpiler emits $obj['key'] for JS property access, so PHP objects
     * must be converted to arrays. Closures are preserved as-is.
     */
    private static function normalizeGlobals(array $globals): array
    {
        foreach ($globals as $k => $v) {
            $globals[$k] = self::normalizeValue($v);
        }
        return $globals;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return $value;
        }
        if (is_object($value)) {
            return new PhpObjectProxy($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::normalizeValue($v);
            }
        }
        return $value;
    }

    private static function denormalizeValue(mixed $value): mixed
    {
        if ($value instanceof TrJsObject) {
            $result = [];
            foreach ($value->toArray() as $key => $item) {
                $result[$key] = self::denormalizeValue($item);
            }
            return $result;
        }

        if ($value instanceof TrJsFunction) {
            return static fn(mixed ...$args): mixed => $value(...$args);
        }

        if ($value instanceof PhpObjectProxy) {
            return $value->target;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::denormalizeValue($item);
            }
        }

        return $value;
    }
}
