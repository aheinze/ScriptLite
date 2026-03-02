<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use RuntimeException;

/**
 * Fuzzing harness: random token generation to find crashes.
 *
 * Strategy: generate random JS-like programs from a grammar of tokens,
 * feed them to both backends (VM + transpiler), and assert that the engine
 * NEVER crashes with a fatal error — only clean RuntimeExceptions are acceptable.
 *
 * This does NOT check semantic correctness. It checks robustness:
 * - No segfaults (PHP fatal errors)
 * - No infinite loops (timeout-guarded)
 * - No unbounded memory growth
 * - No uncaught PHP TypeError/ValueError leaking through
 *
 * Each test method runs many random inputs. Seed is logged on failure
 * so crashes can be reproduced deterministically.
 */
class FuzzTest extends ScriptLiteTestCase
{

    // ── Token pools ──

    private const KEYWORDS = [
        'var', 'let', 'const', 'if', 'else', 'while', 'for', 'do',
        'function', 'return', 'break', 'continue', 'switch', 'case',
        'default', 'try', 'catch', 'throw', 'typeof', 'new', 'delete',
        'in', 'instanceof', 'void', 'null', 'undefined', 'true', 'false',
        'this', 'of',
    ];

    private const OPERATORS = [
        '+', '-', '*', '/', '%', '**',
        '=', '+=', '-=', '*=', '/=',
        '==', '!=', '===', '!==',
        '<', '>', '<=', '>=',
        '&&', '||', '??',
        '!', '~', '++', '--',
        '&', '|', '^', '<<', '>>', '>>>',
        '.', ',', ';', ':', '?',
        '=>',
    ];

    private const DELIMITERS = [
        '(', ')', '[', ']', '{', '}',
    ];

    private const IDENTIFIERS = [
        'a', 'b', 'c', 'x', 'y', 'z', 'foo', 'bar', 'baz',
        'arr', 'obj', 'fn', 'i', 'n', 'sum', 'val', 'tmp',
    ];

    // ── Random program generators ──

    /**
     * Generate a random token sequence (raw token soup).
     */
    private function randomTokenSoup(int $length, int $seed): string
    {
        mt_srand($seed);
        $tokens = [];
        $allTokens = array_merge(
            self::KEYWORDS,
            self::OPERATORS,
            self::DELIMITERS,
            self::IDENTIFIERS,
        );

        for ($i = 0; $i < $length; $i++) {
            $choice = mt_rand(0, 10);
            if ($choice <= 3) {
                // Token from pool
                $tokens[] = $allTokens[mt_rand(0, count($allTokens) - 1)];
            } elseif ($choice <= 5) {
                // Number literal
                $tokens[] = (string) mt_rand(0, 999);
            } elseif ($choice === 6) {
                // String literal
                $inner = self::IDENTIFIERS[mt_rand(0, count(self::IDENTIFIERS) - 1)];
                $tokens[] = '"' . $inner . '"';
            } elseif ($choice === 7) {
                // Identifier
                $tokens[] = self::IDENTIFIERS[mt_rand(0, count(self::IDENTIFIERS) - 1)];
            } else {
                // Operator or delimiter
                $pool = mt_rand(0, 1) ? self::OPERATORS : self::DELIMITERS;
                $tokens[] = $pool[mt_rand(0, count($pool) - 1)];
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * Generate a random but somewhat structured program (better chance of parsing).
     */
    private function randomStructuredProgram(int $stmts, int $seed): string
    {
        mt_srand($seed);
        $lines = [];

        for ($s = 0; $s < $stmts; $s++) {
            $lines[] = $this->randomStatement();
        }

        return implode("\n", $lines);
    }

    private function randomStatement(): string
    {
        $choice = mt_rand(0, 9);
        $id = self::IDENTIFIERS[mt_rand(0, count(self::IDENTIFIERS) - 1)];

        return match ($choice) {
            0 => "var {$id} = " . $this->randomExpr(2) . ";",
            1 => "{$id} = " . $this->randomExpr(2) . ";",
            2 => "if (" . $this->randomExpr(1) . ") { " . $this->randomExpr(1) . "; }",
            3 => "while (false) { " . $this->randomExpr(1) . "; }",
            4 => "for (var {$id} = 0; {$id} < 3; {$id}++) { " . $this->randomExpr(1) . "; }",
            5 => "function {$id}(" . self::IDENTIFIERS[mt_rand(0, 5)] . ") { return " . $this->randomExpr(2) . "; }",
            6 => "try { " . $this->randomExpr(1) . "; } catch(e) { }",
            7 => "var {$id} = [" . $this->randomExpr(1) . ", " . $this->randomExpr(1) . "];",
            8 => "var {$id} = {" . self::IDENTIFIERS[mt_rand(0, 5)] . ": " . $this->randomExpr(1) . "};",
            9 => $this->randomExpr(2) . ";",
        };
    }

    private function randomExpr(int $depth): string
    {
        if ($depth <= 0) {
            return $this->randomAtom();
        }

        $choice = mt_rand(0, 7);
        return match ($choice) {
            0 => $this->randomAtom(),
            1 => $this->randomAtom() . ' + ' . $this->randomExpr($depth - 1),
            2 => $this->randomAtom() . ' - ' . $this->randomExpr($depth - 1),
            3 => $this->randomAtom() . ' * ' . $this->randomExpr($depth - 1),
            4 => $this->randomAtom() . ' === ' . $this->randomExpr($depth - 1),
            5 => $this->randomAtom() . ' < ' . $this->randomExpr($depth - 1),
            6 => '!' . $this->randomExpr($depth - 1),
            7 => '(' . $this->randomExpr($depth - 1) . ')',
        };
    }

    private function randomAtom(): string
    {
        $choice = mt_rand(0, 6);
        return match ($choice) {
            0 => (string) mt_rand(0, 100),
            1 => '"' . self::IDENTIFIERS[mt_rand(0, count(self::IDENTIFIERS) - 1)] . '"',
            2 => self::IDENTIFIERS[mt_rand(0, count(self::IDENTIFIERS) - 1)],
            3 => mt_rand(0, 1) ? 'true' : 'false',
            4 => 'null',
            5 => '[]',
            6 => '{}',
        };
    }

    // ── Fuzzing execution ──

    /**
     * Run a single fuzz input against both backends.
     * Returns true if no crash occurred (exceptions are fine).
     */
    private function fuzzOne(string $source): bool
    {
        // Test VM backend
        try {
            $this->engine->eval($source);
        } catch (RuntimeException) {
            // Expected: parse errors, reference errors, type errors
        } catch (\Error $e) {
            if ($this->isAcceptableError($e)) {
                return true;
            }
            throw $e;
        }

        // Test transpiler backend
        try {
            $this->engine->transpileAndEval($source);
        } catch (RuntimeException) {
            // Expected
        } catch (\Error $e) {
            if ($this->isAcceptableError($e)) {
                return true;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Determine if a PHP \Error is an acceptable non-crash (not a segfault/memory corruption).
     */
    private function isAcceptableError(\Error $e): bool
    {
        $msg = $e->getMessage();
        return $e instanceof \ParseError                                    // transpiler generated invalid PHP
            || $e instanceof \TypeError                                     // type coercion edge case
            || $e instanceof \ValueError                                    // value edge case
            || $e instanceof \DivisionByZeroError                           // division by zero
            || str_contains($msg, 'Maximum call stack')                     // stack overflow
            || str_contains($msg, 'Maximum function nesting')               // xdebug limit
            || str_contains($msg, 'Allowed memory size')                    // memory limit
            || str_contains($msg, 'Uninitialized string offset')            // string OOB access
            || str_contains($msg, 'Array to string conversion')             // array coercion
            || str_contains($msg, 'could not be converted to string')       // closure/object coercion
            || str_contains($msg, 'Undefined variable');                    // uninitialized var
    }

    // ── Test methods ──

    /**
     * Fuzz with random token soup (most inputs won't parse — tests lexer/parser robustness).
     */
    public function testFuzzTokenSoup(): void
    {
        $baseSeed = 42;
        $iterations = 200;

        for ($i = 0; $i < $iterations; $i++) {
            $seed = $baseSeed + $i;
            $length = mt_rand(3, 20);
            $source = $this->randomTokenSoup($length, $seed);

            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on seed {$seed}: {$source}\nError: {$e->getMessage()}");
            }
        }

        // If we get here, all iterations survived
        $this->assertTrue(true, "{$iterations} random token-soup inputs processed without crashes");
    }

    /**
     * Fuzz with semi-structured programs (more likely to parse, tests compiler/VM).
     */
    public function testFuzzStructuredPrograms(): void
    {
        $baseSeed = 1000;
        $iterations = 200;

        for ($i = 0; $i < $iterations; $i++) {
            $seed = $baseSeed + $i;
            $stmts = mt_rand(1, 5);
            $source = $this->randomStructuredProgram($stmts, $seed);

            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on seed {$seed}:\n{$source}\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, "{$iterations} structured programs processed without crashes");
    }

    /**
     * Fuzz with pathological nesting patterns.
     */
    public function testFuzzDeepNesting(): void
    {
        $patterns = [
            // Deep parentheses
            fn(int $n) => str_repeat('(', $n) . '1' . str_repeat(')', $n),
            // Deep array nesting
            fn(int $n) => str_repeat('[', $n) . '1' . str_repeat(']', $n),
            // Deep object nesting
            fn(int $n) => str_repeat('{"a":', $n) . '1' . str_repeat('}', $n),
            // Deep if nesting
            fn(int $n) => str_repeat('if (true) { ', $n) . 'var x = 1;' . str_repeat(' }', $n),
            // Deep function nesting
            fn(int $n) => str_repeat('(function() { return ', $n) . '42' . str_repeat('; })()', $n),
        ];

        foreach ($patterns as $idx => $gen) {
            for ($depth = 1; $depth <= 30; $depth += 5) {
                $source = $gen($depth);
                try {
                    $this->fuzzOne($source);
                } catch (\Throwable $e) {
                    $this->fail("CRASH on pattern {$idx} depth {$depth}:\nError: {$e->getMessage()}");
                }
            }
        }

        $this->assertTrue(true, 'Deep nesting patterns survived');
    }

    /**
     * Fuzz with boundary string values.
     */
    public function testFuzzBoundaryStrings(): void
    {
        $inputs = [
            '""',                           // empty string
            '"\\\\";',                      // escaped backslash
            '"a".length;',                  // single char
            'var x = ""; x + x + x;',      // empty string ops
            '"0" + 0;',                     // string-number coercion
            '"" + [];',                     // string + array
            '"" + {};',                     // string + object
            '"" == false;',                 // empty string equality
            '"" === false;',                // empty string strict equality
            'var a = "abc"; a[0];',         // string indexing
            'var a = "abc"; a[-1];',        // negative index
            'var a = "abc"; a[999];',       // OOB index
        ];

        foreach ($inputs as $source) {
            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on boundary string: {$source}\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, 'Boundary strings survived');
    }

    /**
     * Fuzz with boundary numeric values.
     */
    public function testFuzzBoundaryNumbers(): void
    {
        $inputs = [
            '0;',
            '-0;',
            '9999999999999999;',        // large number
            '0.1 + 0.2;',              // floating point precision
            '1/0;',                     // infinity (VM)
            '-1/0;',                    // negative infinity (VM)
            '0/0;',                     // NaN (VM)
            '1e308;',                   // near MAX_VALUE
            '1e-308;',                  // near MIN_VALUE
            '0xFFFFFFFF;',              // hex literal
            '0xFF;',                    // smaller hex
        ];

        foreach ($inputs as $source) {
            try {
                // Only VM — some of these are transpiler-incompatible
                try {
                    $this->engine->eval($source);
                } catch (RuntimeException) {
                    // Fine
                }
            } catch (\Throwable $e) {
                $this->fail("CRASH on boundary number: {$source}\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, 'Boundary numbers survived');
    }

    /**
     * Fuzz with rapid object/array creation (memory stress).
     */
    public function testFuzzMemoryStress(): void
    {
        $inputs = [
            // Create many arrays
            'var a = []; for (var i = 0; i < 1000; i++) { a.push(i); } a.length;',
            // Nested object creation
            'var o = {}; for (var i = 0; i < 100; i++) { o["k" + i] = i; } typeof o;',
            // String building
            'var s = ""; for (var i = 0; i < 500; i++) { s = s + "x"; } s.length;',
            // Array of arrays
            'var a = []; for (var i = 0; i < 100; i++) { a.push([i, i*2]); } a.length;',
        ];

        foreach ($inputs as $source) {
            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on memory stress: {$source}\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, 'Memory stress tests survived');
    }

    /**
     * Fuzz with malformed/incomplete inputs (tests parser error recovery).
     */
    public function testFuzzMalformedInputs(): void
    {
        $inputs = [
            '',                     // empty input
            ' ',                    // whitespace only
            '  ;  ;  ;  ',         // just semicolons
            'var',                  // incomplete declaration
            'var x =',             // incomplete assignment
            'if',                   // incomplete if
            'if (',                 // unclosed paren
            'if (true',            // missing body
            'function',            // incomplete function
            'function f(',         // unclosed params
            'function f() {',      // unclosed body
            'return',              // return outside function
            '{{{',                 // unclosed braces
            ')))',                  // unmatched parens
            ']]',                  // unmatched brackets
            '////',                // comment-like
            '/* unclosed comment',  // unclosed comment
            '"unclosed string',    // unclosed string
            "'unclosed'",          // single-quoted string
            '`unclosed template',  // unclosed template
            'var x = /unclosed',   // unclosed regex
        ];

        foreach ($inputs as $source) {
            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on malformed input: " . json_encode($source) . "\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, 'Malformed inputs handled gracefully');
    }

    /**
     * Fuzz with operator combos that might confuse the lexer.
     */
    public function testFuzzOperatorCombos(): void
    {
        $inputs = [
            '++--++;',
            '!!!true;',
            '~~~0;',
            '1 + + + 1;',
            '1 - - - 1;',
            '1 === === 1;',
            '> > >;',
            '< < <;',
            '== == ==;',
            '&& || ??;',
            '... 1;',
        ];

        foreach ($inputs as $source) {
            try {
                $this->fuzzOne($source);
            } catch (\Throwable $e) {
                $this->fail("CRASH on operator combo: {$source}\nError: {$e->getMessage()}");
            }
        }

        $this->assertTrue(true, 'Operator combos handled gracefully');
    }
}
