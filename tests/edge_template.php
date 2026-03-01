<?php
require __DIR__ . '/../vendor/autoload.php';
use ScriptLite\Engine;

$e = new Engine();
$tests = 0;
$pass = 0;

function check(string $desc, mixed $expected, mixed $actual): void {
    global $tests, $pass;
    $tests++;
    if ($expected === $actual) {
        $pass++;
        echo "  PASS  {$desc}\n";
    } else {
        echo "  FAIL  {$desc}\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    }
}

echo "=== Edge Case Tests: Template Literals ===\n\n";

echo "--- 1. Dollar sign without braces ---\n";
check('literal $', '$100', $e->eval('`$100`'));
check('literal $x', 'price: $50', $e->eval('`price: $50`'));

echo "\n--- 2. Nested template literals ---\n";
check('inner template', 'Hello Alice!', $e->eval('var name = "Alice"; `Hello ${`${name}`}!`'));
check('outer+inner text', 'a b c d', $e->eval('var x = "c"; `a ${`b ${x}`} d`'));

echo "\n--- 3. Object property in interpolation ---\n";
check('obj property', 'val: 1', $e->eval('var obj = {a: 1}; `val: ${obj.a}`'));

echo "\n--- 4. Array access in interpolation ---\n";
check('arr[0]', 'first: hello', $e->eval('var arr = ["hello", "world"]; `first: ${arr[0]}`'));
check('arr[1]', 'second: world', $e->eval('var arr = ["hello", "world"]; `second: ${arr[1]}`'));

echo "\n--- 5. Math in interpolation ---\n";
check('Math.floor', 'val: 3', $e->eval('`val: ${Math.floor(3.7)}`'));
check('complex expr', 'result: 15', $e->eval('`result: ${(2 + 3) * 3}`'));

echo "\n--- 6. Template in loop ---\n";
check('loop concat', '0-1-2-', $e->eval('
    var result = "";
    for (var i = 0; i < 3; i += 1) {
        result = result + `${i}-`;
    }
    result
'));

echo "\n--- 7. Template as function argument ---\n";
check('func arg', 5, $e->eval('
    function len(s) { return s.length; }
    len(`hello`)
'));
check('func arg interpolated', 11, $e->eval('
    function len(s) { return s.length; }
    var name = "world";
    len(`hello ${name}`)
'));

echo "\n--- 8. Template assigned to variable ---\n";
check('var assignment', 'hi there', $e->eval('var x = `hi there`; x'));
check('var with interp', 'count: 42', $e->eval('var n = 42; var msg = `count: ${n}`; msg'));

echo "\n--- 9. Multiple templates in expression ---\n";
check('concat two templates', 'helloworld', $e->eval('`hello` + `world`'));
check('concat template+str', 'ab', $e->eval('`a` + "b"'));

echo "\n--- 10. Undefined coercion ---\n";
check('undefined', 'value: undefined', $e->eval('`value: ${undefined}`'));

echo "\n--- 11. Template with quotes inside ---\n";
check('single quotes', "it's here", $e->eval("`it's here`"));
check('double quotes', 'he said "hi"', $e->eval('`he said "hi"`'));

echo "\n--- 12. Expressions in interpolation ---\n";
check('nested ternary', 'yes', $e->eval('var x = 1; `${x > 0 ? "yes" : "no"}`'));
check('logical in interp', 'ok', $e->eval('var a = "ok"; `${a || "default"}`'));

echo "\n--- 13. Empty interpolation pieces ---\n";
check('only interps', '12', $e->eval('var a = 1; var b = 2; `${a}${b}`'));
check('three adjacent', '123', $e->eval('`${1}${2}${3}`'));

echo "\n--- 14. Number coercion edge cases ---\n";
check('float', 'pi: 3.14', $e->eval('`pi: ${3.14}`'));
check('zero', 'val: 0', $e->eval('`val: ${0}`'));
check('negative', 'val: -5', $e->eval('`val: ${-5}`'));

echo "\n--- 15. Transpiler edge cases ---\n";
$php = $e->transpile('`$100`');
check('TR: dollar sign', '$100', $e->evalTranspiled($php));

$php = $e->transpile('var name = "Alice"; `Hello ${`${name}`}!`');
check('TR: nested templates', 'Hello Alice!', $e->evalTranspiled($php));

$php = $e->transpile('`value: ${undefined}`');
check('TR: undefined', 'value: undefined', $e->evalTranspiled($php));

$php = $e->transpile('var arr = ["hello", "world"]; `first: ${arr[0]}`');
check('TR: array access', 'first: hello', $e->evalTranspiled($php));

$php = $e->transpile('var result = ""; for (var i = 0; i < 3; i += 1) { result = result + `${i}-`; } result');
check('TR: loop concat', '0-1-2-', $e->evalTranspiled($php));

$php = $e->transpile('`${1}${2}${3}`');
check('TR: three adjacent', '123', $e->evalTranspiled($php));

$php = $e->transpile('var x = 1; `${x > 0 ? "yes" : "no"}`');
check('TR: ternary in interp', 'yes', $e->evalTranspiled($php));

$php = $e->transpile('`hello` + `world`');
check('TR: template concat', 'helloworld', $e->evalTranspiled($php));

echo "\n=== Results: {$pass}/{$tests} passed ===\n";
if ($pass < $tests) { exit(1); }
