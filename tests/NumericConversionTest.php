<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use ScriptLite\Engine;

/**
 * NaN, ±Infinity and out-of-range numbers flowing into integer and string conversions.
 *
 * PHP 8.5 warns when such values hit a raw (int)/(string) cast ("The float NAN is not
 * representable as an int", "unexpected NAN value was coerced to string"). Applications that
 * turn warnings into exceptions then lose the whole script result. The pure-PHP backends now
 * use JS conversions (ToInt32, ToUint32, ToIntegerOrInfinity, Number::toString, property keys)
 * everywhere instead of raw casts.
 *
 * Every case runs on the PHP VM, the transpiler and (when loaded) the C extension, must not raise
 * any PHP warning, notice or deprecation, and must give the ECMAScript result (all expectations
 * were checked against Node.js). In C the same values used to hit undefined-behaviour casts
 * (NaN | 0 gave -2147483648, "x".padStart(NaN) exhausted memory).
 */
final class NumericConversionTest extends ScriptLiteTestCase
{
    /** @return array<string, \Closure(string): mixed> */
    private function backends(): array
    {
        $engine = new Engine(false);

        $backends = [
            'VM' => static fn(string $source): mixed => $engine->eval($source),
            'Transpiler' => static fn(string $source): mixed => $engine->transpileAndEval($source),
        ];

        if (extension_loaded('scriptlite')) {
            $native = new Engine(true);
            $backends['C extension'] = static fn(string $source): mixed => $native->eval($source);
        }

        return $backends;
    }

    /**
     * Run $fn and return every PHP diagnostic it raised.
     *
     * Diagnostics are caught by a handler and, for the transpiler, also via error_get_last():
     * Engine::runTranspiled() installs its own E_WARNING handler, and warnings it declines go to
     * PHP's default handler instead of ours.
     *
     * @return list<string>
     */
    private function capturePhpDiagnostics(\Closure $fn): array
    {
        $issues = [];
        set_error_handler(static function (int $errno, string $message, string $file, int $line) use (&$issues): bool {
            if ((error_reporting() & $errno) !== 0) {
                $issues[] = "{$message} ({$file}:{$line})";
            }
            return true;
        });
        error_clear_last();

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        $last = error_get_last();
        // Engine::getCachedTranspiledFile() calls @opcache_compile_file(), which emits an
        // (intentionally suppressed) notice when OPcache is not enabled for the CLI.
        if ($last !== null && !str_contains($last['message'], 'OPcache')) {
            $issues[] = "{$last['message']} ({$last['file']}:{$last['line']})";
        }

        return array_values(array_unique($issues));
    }

    private function assertJsResult(string $source, mixed $expected): void
    {
        foreach ($this->backends() as $backend => $run) {
            $actual = null;
            $issues = $this->capturePhpDiagnostics(function () use ($run, $source, &$actual): void {
                $actual = $run($source);
            });

            $this->assertSame([], $issues, "{$backend}: PHP diagnostics for: {$source}");

            if (is_float($expected) && is_nan($expected)) {
                $this->assertIsFloat($actual, "{$backend} failed for: {$source}");
                $this->assertNan($actual, "{$backend} failed for: {$source}");
                continue;
            }

            $this->assertSame($expected, $actual, "{$backend} failed for: {$source}");
        }
    }

    /** Bitwise operators use ToInt32 / ToUint32. */
    public static function bitwiseCases(): array
    {
        return [
            'NaN | 0' => ['NaN | 0', 0],
            'Infinity | 0' => ['Infinity | 0', 0],
            '-Infinity >> 0' => ['-Infinity >> 0', 0],
            '~NaN' => ['~NaN', -1],
            'NaN << 1' => ['NaN << 1', 0],
            'NaN >>> 0' => ['NaN >>> 0', 0],
            'undefined | 0' => ['undefined | 0', 0],
            '"abc" | 0' => ['"abc" | 0', 0],
            '"12" | 0' => ['"12" | 0', 12],
            'null | 0' => ['null | 0', 0],
            'true | 0' => ['true | 0', 1],
            '1.9 | 0' => ['1.9 | 0', 1],
            '-1.9 | 0' => ['-1.9 | 0', -1],
            '2147483648 | 0' => ['2147483648 | 0', -2147483648],
            '-2147483649 | 0' => ['-2147483649 | 0', 2147483647],
            '(2**53 + 2) | 0' => ['(2**53 + 2) | 0', 2],
            '2**70 | 0' => ['2**70 | 0', 0],
            '-(2**64) | 0' => ['-(2**64) | 0', 0],
            '1 << 31' => ['1 << 31', -2147483648],
            '1 << 32' => ['1 << 32', 1],
            '1 << -1' => ['1 << -1', -2147483648],
            '5 >> -1' => ['5 >> -1', 0],
            '~2147483648' => ['~2147483648', 2147483647],
            '-1 >>> 0' => ['-1 >>> 0', 4294967295],
            '-8 >>> 28' => ['-8 >>> 28', 15],
            '-8 >> 1' => ['-8 >> 1', -4],
            '255 ^ 1.5' => ['255 ^ 1.5', 254],
            '9007199254740993 * 1024 | 0' => ['9007199254740993 * 1024 | 0', 0],
            'let x = NaN; x |= 0; x' => ['let x = NaN; x |= 0; x', 0],
            'let x = 1; x <<= 31; x' => ['let x = 1; x <<= 31; x', -2147483648],
            'let x = 2**40; x >>>= 0; x' => ['let x = 2**40; x >>>= 0; x', 0],
            'let o = {v: 1/0}; o.v |= 0; o.v' => ['let o = {v: 1/0}; o.v |= 0; o.v', 0],
            'let o = {v: 5}; o["v"] <<= 40; o.v' => ['let o = {v: 5}; o["v"] <<= 40; o.v', 1280],
            'let a = [0/0]; a[0] ^= 1; a[0]' => ['let a = [0/0]; a[0] ^= 1; a[0]', 1],
            'let x = 7; x &= 0/0; x' => ['let x = 7; x &= 0/0; x', 0],
            'let s = "hello world", h = 0; for (let i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0 } h' => ['let s = "hello world", h = 0; for (let i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0 } h', 1794106052],
            'let price = 10, qty = 0; (price / qty) | 0' => ['let price = 10, qty = 0; (price / qty) | 0', 0],
        ];
    }

    #[DataProvider('bitwiseCases')]
    public function testBitwise(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** NaN / Infinity stringify like JS. */
    public static function toStringCases(): array
    {
        return [
            'NaN + ""' => ['NaN + ""', 'NaN'],
            'Infinity + ""' => ['Infinity + ""', 'Infinity'],
            '"" + (-1/0)' => ['"" + (-1/0)', '-Infinity'],
            '`v=${0/0}`' => ['`v=${0/0}`', 'v=NaN'],
            '`${0/0}-${1/0}-${-1/0}`' => ['`${0/0}-${1/0}-${-1/0}`', 'NaN-Infinity--Infinity'],
            'let s = "v"; s += 0/0; s' => ['let s = "v"; s += 0/0; s', 'vNaN'],
            'let o = {s: "v"}; o.s += 1/0; o.s' => ['let o = {s: "v"}; o.s += 1/0; o.s', 'vInfinity'],
            'encodeURIComponent(0/0)' => ['encodeURIComponent(0/0)', 'NaN'],
            'encodeURIComponent(true)' => ['encodeURIComponent(true)', 'true'],
            '"abc".replace("b", 0/0)' => ['"abc".replace("b", 0/0)', 'aNaNc'],
            '"abc".concat(0/0, 1.5)' => ['"abc".concat(0/0, 1.5)', 'abcNaN1.5'],
            '"aNaNb".indexOf(0/0)' => ['"aNaNb".indexOf(0/0)', 1],
            '[NaN, Infinity].join(",")' => ['[NaN, Infinity].join(",")', 'NaN,Infinity'],
            '[1, NaN, true, null, 2.5].join("-")' => ['[1, NaN, true, null, 2.5].join("-")', '1-NaN-true--2.5'],
            '[[1, 2], [3]].join(";")' => ['[[1, 2], [3]].join(";")', '1,2;3'],
            '`${[1, 2]}`' => ['`${[1, 2]}`', '1,2'],
            'let s = "n="; s += true; s' => ['let s = "n="; s += true; s', 'n=true'],
        ];
    }

    #[DataProvider('toStringCases')]
    public function testToString(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Index, position and count arguments use ToIntegerOrInfinity. */
    public static function positionsCases(): array
    {
        return [
            '"abc".charAt(NaN)' => ['"abc".charAt(NaN)', 'a'],
            '"abc".charAt(Infinity)' => ['"abc".charAt(Infinity)', ''],
            '"abc".charCodeAt(NaN)' => ['"abc".charCodeAt(NaN)', 97],
            '"abc".charCodeAt(Infinity)' => ['"abc".charCodeAt(Infinity)', NAN],
            '"abc".at(Infinity)' => ['"abc".at(Infinity)', NULL],
            '[1, 2].at(NaN)' => ['[1, 2].at(NaN)', 1],
            '[1, 2, 3].at(-Infinity)' => ['[1, 2, 3].at(-Infinity)', NULL],
            '"abcabc".indexOf("c", Infinity)' => ['"abcabc".indexOf("c", Infinity)', -1],
            '"abcabc".indexOf("c", NaN)' => ['"abcabc".indexOf("c", NaN)', 2],
            '"hello".indexOf("l", 9)' => ['"hello".indexOf("l", 9)', -1],
            '"abc".includes("a", NaN)' => ['"abc".includes("a", NaN)', true],
            '"abc".startsWith("a", -Infinity)' => ['"abc".startsWith("a", -Infinity)', true],
            '"abc".endsWith("c", Infinity)' => ['"abc".endsWith("c", Infinity)', true],
            '"abc".endsWith("a", NaN)' => ['"abc".endsWith("a", NaN)', false],
            '"abc".slice(NaN)' => ['"abc".slice(NaN)', 'abc'],
            '"abc".slice(0, Infinity)' => ['"abc".slice(0, Infinity)', 'abc'],
            '"abc".slice(-Infinity)' => ['"abc".slice(-Infinity)', 'abc'],
            '"abc".substring(NaN, Infinity)' => ['"abc".substring(NaN, Infinity)', 'abc'],
            '"abc".substring(-Infinity, Infinity)' => ['"abc".substring(-Infinity, Infinity)', 'abc'],
            '"hello".substring(-1, 2)' => ['"hello".substring(-1, 2)', 'he'],
            '"hello".substring(-2)' => ['"hello".substring(-2)', 'hello'],
            '"a,b,c".split(",", NaN)' => ['"a,b,c".split(",", NaN)', []],
            '"a,b,c".split(",", Infinity)' => ['"a,b,c".split(",", Infinity)', []],
            '"a,b,c".split(",", -1)' => ['"a,b,c".split(",", -1)', ['a', 'b', 'c']],
            '"a1b2c".split(/\\d/, 2)' => ['"a1b2c".split(/\\d/, 2)', ['a', 'b']],
            '"x".padStart(NaN, "-")' => ['"x".padStart(NaN, "-")', 'x'],
            '"x".padEnd(NaN, "-")' => ['"x".padEnd(NaN, "-")', 'x'],
            '"x".padStart(3, NaN)' => ['"x".padStart(3, NaN)', 'Nax'],
            '"ab".repeat(NaN)' => ['"ab".repeat(NaN)', ''],
            '"ab".repeat(2.7)' => ['"ab".repeat(2.7)', 'abab'],
        ];
    }

    #[DataProvider('positionsCases')]
    public function testPositions(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Array methods with NaN / Infinity arguments. */
    public static function arraysCases(): array
    {
        return [
            '[1, 2, 3].slice(-Infinity, Infinity)' => ['[1, 2, 3].slice(-Infinity, Infinity)', [1, 2, 3]],
            '[1, 2, 3].slice(NaN, -Infinity)' => ['[1, 2, 3].slice(NaN, -Infinity)', []],
            '[1, 2, 3].slice(1, Infinity)' => ['[1, 2, 3].slice(1, Infinity)', [2, 3]],
            '[1, 2, 3, 4, 5].slice(1, -1)' => ['[1, 2, 3, 4, 5].slice(1, -1)', [2, 3, 4]],
            '[1, 2, 3, 4, 5].slice()' => ['[1, 2, 3, 4, 5].slice()', [1, 2, 3, 4, 5]],
            'let a = [1, 2, 3]; a.splice(NaN, Infinity)' => ['let a = [1, 2, 3]; a.splice(NaN, Infinity)', [1, 2, 3]],
            'let a = [1, 2, 3, 4]; a.splice(1, Infinity)' => ['let a = [1, 2, 3, 4]; a.splice(1, Infinity)', [2, 3, 4]],
            'let a = [1, 2, 3, 4]; a.splice(1, NaN); a' => ['let a = [1, 2, 3, 4]; a.splice(1, NaN); a', [1, 2, 3, 4]],
            'let a = [1, 2, 3]; a.splice(0, -1); a' => ['let a = [1, 2, 3]; a.splice(0, -1); a', [1, 2, 3]],
            '[1, 2, 3].fill(0, NaN)' => ['[1, 2, 3].fill(0, NaN)', [0, 0, 0]],
            'let a = [1, 2, 3, 4]; a.fill(7, -2); a' => ['let a = [1, 2, 3, 4]; a.fill(7, -2); a', [1, 2, 7, 7]],
            'let a = [1, 2, 3]; a.fill(0, 1, Infinity); a' => ['let a = [1, 2, 3]; a.fill(0, 1, Infinity); a', [1, 0, 0]],
            '[[1, [2, [3]]]].flat(Infinity)' => ['[[1, [2, [3]]]].flat(Infinity)', [1, 2, 3]],
            '[[1, [2]]].flat(NaN)' => ['[[1, [2]]].flat(NaN)', [[1, [2]]]],
            'let a = [3, 1, 2]; a.sort((x, y) => (x - y) / 10)' => ['let a = [3, 1, 2]; a.sort((x, y) => (x - y) / 10)', [1, 2, 3]],
            'let a = [0.3, 0.1, 0.2]; a.sort((x, y) => x - y)' => ['let a = [0.3, 0.1, 0.2]; a.sort((x, y) => x - y)', [0.1, 0.2, 0.3]],
            'let a = [3, 1, 2]; a.sort((x, y) => x < y ? -Infinity : Infinity)' => ['let a = [3, 1, 2]; a.sort((x, y) => x < y ? -Infinity : Infinity)', [1, 2, 3]],
            'let a = [3, 2, 1]; a.sort((x, y) => NaN)' => ['let a = [3, 2, 1]; a.sort((x, y) => NaN)', [3, 2, 1]],
        ];
    }

    #[DataProvider('arraysCases')]
    public function testArrays(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Property keys from NaN / Infinity / fractions / bools. */
    public static function keysCases(): array
    {
        return [
            'let o = {}; o[NaN] = 1; Object.keys(o)' => ['let o = {}; o[NaN] = 1; Object.keys(o)', ['NaN']],
            'Object.keys({[0/0]: 1})' => ['Object.keys({[0/0]: 1})', ['NaN']],
            'let o = {}; o[Infinity] = 1; o[-Infinity] = 2; Object.keys(o)' => ['let o = {}; o[Infinity] = 1; o[-Infinity] = 2; Object.keys(o)', ['Infinity', '-Infinity']],
            'Object.keys({[1/0]: 1, [1.5]: 2})' => ['Object.keys({[1/0]: 1, [1.5]: 2})', ['Infinity', '1.5']],
            'let o = {}; o[1.5] = "x"; o["1.5"]' => ['let o = {}; o[1.5] = "x"; o["1.5"]', 'x'],
            'let o = {}; o[null] = 1; o[true] = 2; Object.keys(o)' => ['let o = {}; o[null] = 1; o[true] = 2; Object.keys(o)', ['null', 'true']],
            '[1, 2, 3][NaN]' => ['[1, 2, 3][NaN]', NULL],
            '[1, 2, 3][-1/0]' => ['[1, 2, 3][-1/0]', NULL],
            '"abc"[NaN]' => ['"abc"[NaN]', NULL],
            '"abc"[1/0]' => ['"abc"[1/0]', NULL],
            'let s = "hello"; let i = 1.5; s[i]' => ['let s = "hello"; let i = 1.5; s[i]', NULL],
            'let a = [10, 20]; let i = 0.5 * 2; a[i]' => ['let a = [10, 20]; let i = 0.5 * 2; a[i]', 20],
            'NaN in {NaN: 1}' => ['NaN in {NaN: 1}', true],
            'let o = {NaN: 1}; delete o[NaN]; Object.keys(o).length' => ['let o = {NaN: 1}; delete o[NaN]; Object.keys(o).length', 0],
            'let o = {NaN: 1}; o.hasOwnProperty(NaN)' => ['let o = {NaN: 1}; o.hasOwnProperty(NaN)', true],
        ];
    }

    #[DataProvider('keysCases')]
    public function testKeys(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Number formatting and Math. */
    public static function numbersCases(): array
    {
        return [
            '(0/0).toFixed(2)' => ['(0/0).toFixed(2)', 'NaN'],
            '(1/0).toFixed(2)' => ['(1/0).toFixed(2)', 'Infinity'],
            '(-1/0).toFixed(1)' => ['(-1/0).toFixed(1)', '-Infinity'],
            '(1/0).toPrecision(3)' => ['(1/0).toPrecision(3)', 'Infinity'],
            '(0/0).toExponential(2)' => ['(0/0).toExponential(2)', 'NaN'],
            '(0/0).toString(2)' => ['(0/0).toString(2)', 'NaN'],
            '(1/0).toString(16)' => ['(1/0).toString(16)', 'Infinity'],
            '(-255).toString(16)' => ['(-255).toString(16)', '-ff'],
            '(255).toString(2.9)' => ['(255).toString(2.9)', '11111111'],
            '(3.14159).toFixed(2)' => ['(3.14159).toFixed(2)', '3.14'],
            // toFixed rounds the exact binary value; exact ties go away from zero (not to even)
            '(12.5).toFixed(0)' => ['(12.5).toFixed(0)', '13'],
            '(2.5).toFixed(0)' => ['(2.5).toFixed(0)', '3'],
            '(9.5).toFixed(0)' => ['(9.5).toFixed(0)', '10'],
            '(-2.5).toFixed(0)' => ['(-2.5).toFixed(0)', '-3'],
            '(0.125).toFixed(2)' => ['(0.125).toFixed(2)', '0.13'],
            '(1.005).toFixed(2)' => ['(1.005).toFixed(2)', '1.00'],
            '(1.255).toFixed(2)' => ['(1.255).toFixed(2)', '1.25'],
            '(1.45).toFixed(1)' => ['(1.45).toFixed(1)', '1.4'],
            '(-0.0001).toFixed(2)' => ['(-0.0001).toFixed(2)', '-0.00'],
            '(123.456).toFixed(5)' => ['(123.456).toFixed(5)', '123.45600'],
            // JSON has no NaN/Infinity
            'JSON.stringify(NaN)' => ['JSON.stringify(NaN)', 'null'],
            'JSON.stringify([0/0, 1/0, 1])' => ['JSON.stringify([0/0, 1/0, 1])', '[null,null,1]'],
            'JSON.stringify({a: 0/0, b: 2})' => ['JSON.stringify({a: 0/0, b: 2})', '{"a":null,"b":2}'],
            'Math.floor(NaN)' => ['Math.floor(NaN)', NAN],
            'Math.round(Infinity)' => ['Math.round(Infinity)', INF],
            'Math.ceil(0/0)' => ['Math.ceil(0/0)', NAN],
            'Math.trunc(NaN)' => ['Math.trunc(NaN)', NAN],
            'Math.trunc(-1/0)' => ['Math.trunc(-1/0)', -INF],
            'Math.trunc(-4.7)' => ['Math.trunc(-4.7)', -4],
            'Math.clz32(NaN)' => ['Math.clz32(NaN)', 32],
            'Math.clz32(1/0)' => ['Math.clz32(1/0)', 32],
            'Math.clz32(0.5)' => ['Math.clz32(0.5)', 32],
            'Math.clz32(1)' => ['Math.clz32(1)', 31],
            'Math.clz32(-1)' => ['Math.clz32(-1)', 0],
            'String.fromCharCode(65.9)' => ['String.fromCharCode(65.9)', 'A'],
            'String.fromCharCode(65 + 65536)' => ['String.fromCharCode(65 + 65536)', 'A'],
            'parseInt("123", NaN)' => ['parseInt("123", NaN)', 123],
            'parseInt("11", 1/0)' => ['parseInt("11", 1/0)', 11],
            'parseInt("11", 2.9)' => ['parseInt("11", 2.9)', 3],
            'parseInt("1e3")' => ['parseInt("1e3")', 1],
            'parseInt("12px")' => ['parseInt("12px")', 12],
            'Number.isInteger(2**60)' => ['Number.isInteger(2**60)', true],
            'Number.isInteger(1/0)' => ['Number.isInteger(1/0)', false],
            '9223372036854775807 + 1' => ['9223372036854775807 + 1', 9.223372036854776E+18],
        ];
    }

    #[DataProvider('numbersCases')]
    public function testNumbers(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Invalid Date. */
    public static function datesCases(): array
    {
        return [
            'new Date(0/0).getTime()' => ['new Date(0/0).getTime()', NAN],
            'new Date(2020, 0/0).getTime()' => ['new Date(2020, 0/0).getTime()', NAN],
            'new Date(0/0).getFullYear()' => ['new Date(0/0).getFullYear()', NAN],
            'new Date(0/0).toString()' => ['new Date(0/0).toString()', 'Invalid Date'],
            'let d = new Date(0/0); d.setTime(1000); d.getTime()' => ['let d = new Date(0/0); d.setTime(1000); d.getTime()', 1000],
            'new Date(86400000).getDate()' => ['new Date(86400000).getDate()', 2],
            'new Date(0).toISOString()' => ['new Date(0).toISOString()', '1970-01-01T00:00:00.000Z'],
        ];
    }

    #[DataProvider('datesCases')]
    public function testDates(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    /** Computed member writes keep working with normalized keys. */
    public static function writesCases(): array
    {
        return [
            'let a = [[1, 2]]; let i = 0; a[i][1] = 5; a' => ['let a = [[1, 2]]; let i = 0; a[i][1] = 5; a', [[1, 5]]],
            'let a = [{x: 1}]; let i = 0; a[i].x += 2; a[0].x' => ['let a = [{x: 1}]; let i = 0; a[i].x += 2; a[0].x', 3],
            'let a = [{x: 1}]; let i = 0; a[i].x++; a[0].x' => ['let a = [{x: 1}]; let i = 0; a[i].x++; a[0].x', 2],
            'let a = [[0/0]]; let i = 0, j = 0; a[i][j] = a[i][j] | 0; a' => ['let a = [[0/0]]; let i = 0, j = 0; a[i][j] = a[i][j] | 0; a', [[0]]],
            'let a = [[1, 2], [3, 4]]; for (let i = 0; i < 2; i++) for (let j = 0; j < 2; j++) a[i][j] = a[i][j] * 10; a' => ['let a = [[1, 2], [3, 4]]; for (let i = 0; i < 2; i++) for (let j = 0; j < 2; j++) a[i][j] = a[i][j] * 10; a', [[10, 20], [30, 40]]],
            'let a = [1, 2, 3]; let i = 1.0; a[i] = 9; a' => ['let a = [1, 2, 3]; let i = 1.0; a[i] = 9; a', [1, 9, 3]],
        ];
    }

    #[DataProvider('writesCases')]
    public function testWrites(string $source, mixed $expected): void
    {
        $this->assertJsResult($source, $expected);
    }

    public function testHugeUnicodeEscapeIsASyntaxErrorNotAnIntCast(): void
    {
        foreach ($this->backends() as $backend => $run) {
            // The C extension's parser has no \u{...} escapes at all (it rejects valid ones too).
            if ($backend !== 'C extension') {
                $this->assertSame("\u{1F600}", $run('"\\u{1F600}"'), $backend);
            }

            $message = null;
            $issues = $this->capturePhpDiagnostics(function () use ($run, &$message): void {
                try {
                    $run('"\\u{FFFFFFFFFFFFFFFFFFFF}"');
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                }
            });
            $this->assertSame([], $issues, "{$backend}: PHP diagnostics");
            $this->assertNotNull($message, "{$backend}: expected a syntax error");
            // PHP lexer: LexerException; C extension: its native parser rejects the source
            $this->assertMatchesRegularExpression('/Undefined Unicode code-point|failed to parse/', $message, $backend);
        }
    }

    public function testFlatInfinityOnCyclicArrayIsARangeErrorNotAStackOverflow(): void
    {
        // Transpiled arrays are PHP values (copied on push), so only the VM and the C extension
        // can build a cyclic array.
        foreach ($this->backends() as $backend => $run) {
            if ($backend === 'Transpiler') {
                continue;
            }
            $this->assertSame(3, $run('let a = [1]; a.push(a); a.flat(1).length'), "{$backend}: finite depth still works");

            $message = null;
            $issues = $this->capturePhpDiagnostics(function () use ($run, &$message): void {
                try {
                    $run('let a = [1]; a.push(a); a.flat(Infinity)');
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                }
            });
            $this->assertSame([], $issues, "{$backend}: PHP diagnostics");
            $this->assertNotNull($message, "{$backend}: expected a RangeError");
            $this->assertStringContainsString('RangeError', $message, $backend);
        }
    }

    /** Arguments outside the range JS accepts throw a RangeError instead of exhausting memory or returning garbage. */
    public static function rangeErrorCases(): array
    {
        return [
            '"ab".repeat(Infinity)' => ['"ab".repeat(Infinity)'],
            '"ab".repeat(-1)' => ['"ab".repeat(-1)'],
            '"x".padStart(Infinity)' => ['"x".padStart(Infinity)'],
            '(1.5).toFixed(Infinity)' => ['(1.5).toFixed(Infinity)'],
            '(1.5).toFixed(101)' => ['(1.5).toFixed(101)'],
            '(255).toString(NaN)' => ['(255).toString(NaN)'],
            '(255).toString(1)' => ['(255).toString(1)'],
            '(1).toPrecision(0)' => ['(1).toPrecision(0)'],
            'new Date(0/0).toISOString()' => ['new Date(0/0).toISOString()'],
        ];
    }

    #[DataProvider('rangeErrorCases')]
    public function testRangeErrors(string $source): void
    {
        foreach ($this->backends() as $backend => $run) {
            $message = null;
            $issues = $this->capturePhpDiagnostics(function () use ($run, $source, &$message): void {
                try {
                    $run($source);
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                }
            });
            $this->assertSame([], $issues, "{$backend}: PHP diagnostics for: {$source}");
            $this->assertNotNull($message, "{$backend}: expected a RangeError for: {$source}");
            $this->assertStringContainsString('RangeError', $message, "{$backend}: {$source}");
        }
    }
}
