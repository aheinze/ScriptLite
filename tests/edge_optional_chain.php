<?php

/**
 * Edge-case tests for optional chaining (?.) — runs outside PHPUnit.
 *
 * Usage: php tests/edge_optional_chain.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ScriptLite\Engine;

$engine = new Engine();
$pass = 0;
$fail = 0;

function test(string $label, mixed $expected, mixed $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
    } else {
        $fail++;
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        echo "FAIL: {$label}\n  expected: {$e}\n  actual:   {$a}\n";
    }
}

// ── VM path ──

test('obj?.prop on object', 42, $engine->eval('var o = {x: 42}; o?.x'));
test('obj?.prop on null', null, $engine->eval('var o = null; o?.x'));
test('obj?.prop on undefined', null, $engine->eval('var o = undefined; o?.x'));
test('chained a?.b?.c success', 1, $engine->eval('var a = {b: {c: 1}}; a?.b?.c'));
test('chained a?.b?.c first null', null, $engine->eval('var a = null; a?.b?.c'));
test('chained a?.b?.c middle null', null, $engine->eval('var a = {b: null}; a?.b?.c'));
test('optional then regular', 5, $engine->eval('var a = {b: {c: 5}}; a?.b.c'));
test('regular then optional on null', null, $engine->eval('var a = {b: null}; a.b?.c'));
test('deep chain', 10, $engine->eval('var d = {a: {b: {c: {d: 10}}}}; d?.a?.b?.c?.d'));
test('deep chain breaks', null, $engine->eval('var d = {a: {b: null}}; d?.a?.b?.c?.d'));
test('optional with || fallback', 99, $engine->eval('var o = null; o?.x || 99'));
test('optional with ternary', 'no', $engine->eval('var o = null; o?.x ? "yes" : "no"'));
test('optional on number (non-object)', null, $engine->eval('var x = 42; x?.foo'));
test('optional on string', null, $engine->eval('var s = "hi"; s?.foo'));
test('optional on boolean', null, $engine->eval('var b = true; b?.foo'));
test('array element then optional', null, $engine->eval('
    var arr = [{name: "a"}, null, {name: "c"}];
    arr[1]?.name
'));
test('array element optional success', 'a', $engine->eval('
    var arr = [{name: "a"}, null];
    arr[0]?.name
'));

// ── With PHP interop ──
test('optional on injected null', null, $engine->eval('obj?.x', ['obj' => null]));
test('optional on injected object', 10, $engine->eval('obj?.x', ['obj' => ['x' => 10]]));

// ── Transpiler path ──
$globals = ['obj' => null];
$php = $engine->transpile('obj?.x', $globals);
test('transpiler: obj?.x on null', null, $engine->evalTranspiled($php, ['obj' => null]));
test('transpiler: obj?.x on object', 7, $engine->evalTranspiled($php, ['obj' => ['x' => 7]]));

$php2 = $engine->transpile('a?.b?.c', ['a' => null]);
test('transpiler: a?.b?.c success', 3, $engine->evalTranspiled($php2, ['a' => ['b' => ['c' => 3]]]));
test('transpiler: a?.b?.c first null', null, $engine->evalTranspiled($php2, ['a' => null]));
test('transpiler: a?.b?.c middle null', null, $engine->evalTranspiled($php2, ['a' => ['b' => null]]));

// ── Ternary ambiguity: ? followed by number should stay as ternary ──
test('ternary with decimal', 0.5, $engine->eval('var x = false; x ? 1 : 0.5'));
test('ternary not confused', 1, $engine->eval('var x = true; x ? 1 : 0.5'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
