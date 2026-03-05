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
    private const int MAX_COMPILED_CACHE_SIZE = 32;
    private const int MAX_TRANSPILER_CACHE_SIZE = 32;
    private const int MAX_TRANSPILER_FILE_CACHE_SIZE = 16;
    private const int MAX_PARSE_CACHE_SIZE = 12;

    private ?VirtualMachine $lastVm = null;
    private int $cacheSequence = 0;

    /** @var array<string, array{compiled: CompiledScript, touch: int}> */
    private array $compiledCache = [];

    /** @var array<string, array{phpSource: string, touch: int}> */
    private array $transpileCache = [];

    /** @var array<string, array<string, int>> [key => ['file' => string, 'touch' => int]] */
    private array $transpiledFileCache = [];

    /** @var array<string, array{program: \ScriptLite\Ast\Program, touch: int}> */
    private array $parseCache = [];

    private string $tempDir;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir();
    }

    /**
     * Parse and compile JS source to bytecode.
     */
    public function compile(string $source): CompiledScript
    {
        $program  = $this->parseSourceCached($source);
        $compiler = new Compiler();
        return $compiler->compile($program);
    }

    /**
     * Parse and compile JS source to bytecode with a small LRU cache.
     */
    private function compileCached(string $source): CompiledScript
    {
        $key = md5($source);
        if (isset($this->compiledCache[$key])) {
            $this->compiledCache[$key]['touch'] = ++$this->cacheSequence;
            return $this->compiledCache[$key]['compiled'];
        }

        $compiled = $this->compile($source);
        $this->compiledCache[$key] = [
            'compiled' => $compiled,
            'touch' => ++$this->cacheSequence,
        ];

        if (count($this->compiledCache) > self::MAX_COMPILED_CACHE_SIZE) {
            $oldestKey = null;
            $oldestTouch = PHP_INT_MAX;
            foreach ($this->compiledCache as $cacheKey => $entry) {
                if ($entry['touch'] < $oldestTouch) {
                    $oldestTouch = $entry['touch'];
                    $oldestKey = $cacheKey;
                }
            }
            if ($oldestKey !== null) {
                unset($this->compiledCache[$oldestKey]);
            }
        }

        return $compiled;
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
        return $this->run($this->compileCached($source), $globals);
    }

    /**
     * Transpile JS source to PHP source code.
     *
     * Pass globals to register their names in the scope tracker so inner
     * closures capture them correctly. Only the keys matter at transpile time;
     * actual values are provided at execution time.
     *
     * @param list<string>|array<string, mixed> $globals Variable names (list) or name => value pairs (associative)
     */
    public function transpile(string $source, array $globals = []): string
    {
        $globalNames = self::extractGlobalNames($globals);
        $cacheKeySource = $globalNames === []
            ? $source
            : $source . "\0" . implode('|', $globalNames);
        $cacheKey = md5($cacheKeySource);
        if (isset($this->transpileCache[$cacheKey])) {
            $this->transpileCache[$cacheKey]['touch'] = ++$this->cacheSequence;
            return $this->transpileCache[$cacheKey]['phpSource'];
        }

        $program = $this->parseSourceCached($source);
        $transpiler = new PhpTranspiler();
        $phpSource = $transpiler->transpile($program, $globalNames);
        $this->transpileCache[$cacheKey] = [
            'phpSource' => $phpSource,
            'touch' => ++$this->cacheSequence,
        ];

        if (count($this->transpileCache) > self::MAX_TRANSPILER_CACHE_SIZE) {
            $oldestKey = null;
            $oldestTouch = PHP_INT_MAX;
            foreach ($this->transpileCache as $key => $entry) {
                if ($entry['touch'] < $oldestTouch) {
                    $oldestTouch = $entry['touch'];
                    $oldestKey = $key;
                }
            }
            if ($oldestKey !== null) {
                unset($this->transpileCache[$oldestKey]);
            }
        }

        return $phpSource;
    }

    /**
     * Transpile JS source and return a closure for repeated execution.
     *
     * @param list<string>|array<string, mixed> $globals Variable names (list) or name => value pairs (associative)
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
        $php = $this->transpile($source, $globals);
        $useEval = (bool) ($opts['use_eval'] ?? false); // if false, uses runTranspiled() to avoid eval() memory leak

        if ($useEval) {
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
        $__globals = $globals === [] ? [] : self::normalizeGlobals($globals);
        set_error_handler([self::class, 'handleUndefinedVariableAsRuntimeException'], E_WARNING);
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
        $file = $this->getCachedTranspiledFile($phpSource);
        set_error_handler([self::class, 'handleUndefinedVariableAsRuntimeException'], E_WARNING);
        try {
            $__globals = $globals === [] ? [] : self::normalizeGlobals($globals);
            return self::denormalizeValue(include $file);
        } finally {
            restore_error_handler();
        }
    }

    private function getCachedTranspiledFile(string $phpSource): string
    {
        $cacheKey = md5($phpSource);
        if (isset($this->transpiledFileCache[$cacheKey])) {
            $this->transpiledFileCache[$cacheKey]['touch'] = ++$this->cacheSequence;
            $existing = $this->transpiledFileCache[$cacheKey]['file'];
            if (is_file($existing)) {
                return $existing;
            }
            unset($this->transpiledFileCache[$cacheKey]);
        }

        $file = $this->tempDir . '/scriptlite_cached_' . $cacheKey . '.php';
        if (!is_file($file)) {
            file_put_contents($file, "<?php\n" . $phpSource);
            if (function_exists('opcache_compile_file')) {
                @opcache_compile_file($file);
            }
        }

        $this->transpiledFileCache[$cacheKey] = ['file' => $file, 'touch' => ++$this->cacheSequence];

        if (count($this->transpiledFileCache) > self::MAX_TRANSPILER_FILE_CACHE_SIZE) {
            $oldestKey = null;
            $oldestTouch = PHP_INT_MAX;
            foreach ($this->transpiledFileCache as $key => $entry) {
                if ($entry['touch'] < $oldestTouch) {
                    $oldestTouch = $entry['touch'];
                    $oldestKey = $key;
                }
            }
            if ($oldestKey !== null && isset($this->transpiledFileCache[$oldestKey]['file'])) {
                @unlink($this->transpiledFileCache[$oldestKey]['file']);
                unset($this->transpiledFileCache[$oldestKey]);
            }
        }

        return $file;
    }

    private function parseSourceCached(string $source): \ScriptLite\Ast\Program
    {
        $key = md5($source);
        if (isset($this->parseCache[$key])) {
            $this->parseCache[$key]['touch'] = ++$this->cacheSequence;
            return $this->parseCache[$key]['program'];
        }

        $parser = new Parser($source);
        $program = $parser->parse();

        $this->parseCache[$key] = ['program' => $program, 'touch' => ++$this->cacheSequence];

        if (count($this->parseCache) > self::MAX_PARSE_CACHE_SIZE) {
            $oldestKey = null;
            $oldestTouch = PHP_INT_MAX;
            foreach ($this->parseCache as $cacheKey => $entry) {
                if ($entry['touch'] < $oldestTouch) {
                    $oldestTouch = $entry['touch'];
                    $oldestKey = $cacheKey;
                }
            }
            if ($oldestKey !== null) {
                unset($this->parseCache[$oldestKey]);
            }
        }

        return $program;
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

    /**
     * Accept either a list of names (['acc', 'multiplier']) or an associative
     * array (['acc' => $val, ...]) and return just the names.
     *
     * @param list<string>|array<string, mixed> $globals
     * @return list<string>
     */
    private static function extractGlobalNames(array $globals): array
    {
        if ($globals === [] || array_is_list($globals)) {
            return $globals;
        }
        $globalNames = array_keys($globals);
        sort($globalNames);
        return $globalNames;
    }

    private static function handleUndefinedVariableAsRuntimeException(int $errno, string $msg): bool
    {
        if (!str_starts_with($msg, 'Undefined variable')) {
            return false;
        }

        $start = strpos($msg, '$');
        if ($start === false) {
            throw new \RuntimeException('Undefined variable');
        }

        $name = substr($msg, $start + 1);
        $end = strpos($name, ' ');
        if ($end !== false) {
            $name = substr($name, 0, $end);
        }

        throw new \RuntimeException($name . ' is not defined');
    }
}
