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

echo "=== Edge Case Tests: Arrow Functions ===\n\n";

echo "--- 1. Nested arrows (currying) ---\n";
check('two-level curry', 3, $e->eval('var f = x => y => x + y; f(1)(2)'));
check('three-level curry', 6, $e->eval('var f = a => b => c => a + b + c; f(1)(2)(3)'));

echo "\n--- 2. Arrow in ternary ---\n";
check('arrow in consequent', 10, $e->eval('
    var cond = true;
    var f = cond ? x => x * 2 : x => x * 3;
    f(5)
'));
check('arrow in alternate', 15, $e->eval('
    var cond = false;
    var f = cond ? x => x * 2 : x => x * 3;
    f(5)
'));
check('arrow body with ternary', 'yes', $e->eval('var f = x => x > 0 ? "yes" : "no"; f(1)'));
check('arrow body ternary false', 'no', $e->eval('var f = x => x > 0 ? "yes" : "no"; f(-1)'));

echo "\n--- 3. Arrow with template literal body ---\n";
check('template body', 'hello Alice', $e->eval('var greet = name => `hello ${name}`; greet("Alice")'));
check('template body multi', 'Alice is 30', $e->eval('var desc = (name, age) => `${name} is ${age}`; desc("Alice", 30)'));

echo "\n--- 4. Arrow returning array ---\n";
$r = $e->eval('var f = () => [1, 2, 3]; f()');
check('returns array', [1, 2, 3], $r);
check('returns array map', [2, 4, 6], $e->eval('var f = x => [x, x * 2, x * 3]; f(2)'));

echo "\n--- 5. Arrow returning object (wrapped) ---\n";
// () => ({a: 1}) should return object
$r = $e->eval('var f = () => ({a: 1, b: 2}); f()');
check('returns object a', 1, $r['a'] ?? null);
check('returns object b', 2, $r['b'] ?? null);

echo "\n--- 6. Arrow with logical operators ---\n";
check('logical or body', 'default', $e->eval('var f = x => x || "default"; f("")'));
check('logical or truthy', 'ok', $e->eval('var f = x => x || "default"; f("ok")'));
check('logical and body', true, $e->eval('var f = x => x && true; f(1)'));
check('logical and falsy', 0, $e->eval('var f = x => x && true; f(0)'));

echo "\n--- 7. Arrow with comparison ---\n";
check('greater than', true, $e->eval('var f = x => x > 5; f(10)'));
check('equality', true, $e->eval('var f = (a, b) => a === b; f(1, 1)'));

echo "\n--- 8. Arrow with unary ---\n";
check('negation', -5, $e->eval('var f = x => -x; f(5)'));
check('not', false, $e->eval('var f = x => !x; f(true)'));

echo "\n--- 9. Arrow as method callback ---\n";
check('map', [2, 4, 6], $e->eval('[1, 2, 3].map(x => x * 2)'));
check('filter', [4, 5], $e->eval('[1, 2, 3, 4, 5].filter(x => x > 3)'));
check('forEach accumulate', 6, $e->eval('
    var sum = 0;
    [1, 2, 3].forEach(x => { sum = sum + x; });
    sum
'));

echo "\n--- 10. Arrow IIFE ---\n";
check('IIFE no params', 42, $e->eval('(() => 42)()'));
check('IIFE with param', 10, $e->eval('(x => x * 2)(5)'));
check('IIFE multi params', 7, $e->eval('((a, b) => a + b)(3, 4)'));

echo "\n--- 11. Arrow capturing outer vars ---\n";
check('capture var', 15, $e->eval('
    var factor = 3;
    var f = x => x * factor;
    f(5)
'));
check('capture mutated var', 20, $e->eval('
    var factor = 3;
    var f = x => x * factor;
    factor = 4;
    f(5)
'));
check('capture from function', 10, $e->eval('
    function outer() {
        var x = 10;
        var inner = () => x;
        return inner();
    }
    outer()
'));

echo "\n--- 12. Arrow returning function ---\n";
check('return regular fn', 6, $e->eval('
    var f = x => function(y) { return x + y; };
    f(1)(5)
'));
check('return arrow', 6, $e->eval('
    var f = x => y => x + y;
    var g = f(1);
    g(5)
'));

echo "\n--- 13. Arrow in array literal ---\n";
$r = $e->eval('
    var fns = [x => x + 1, x => x + 2, x => x + 3];
    [fns[0](10), fns[1](10), fns[2](10)]
');
check('arrow array [0]', 11, $r[0]);
check('arrow array [1]', 12, $r[1]);
check('arrow array [2]', 13, $r[2]);

echo "\n--- 14. Arrow with complex expressions ---\n";
check('math expr', 11, $e->eval('var f = (a, b) => a * b + a + b; f(3, 2)'));
check('string concat', 'hello world', $e->eval('var f = (a, b) => a + " " + b; f("hello", "world")'));
check('chained calls', 9, $e->eval('
    var add = a => b => a + b;
    var add5 = add(5);
    add5(4)
'));

echo "\n--- 15. Block body edge cases ---\n";
check('block no return', null, $e->eval('var f = () => {}; f()'));
check('block multi stmt', 30, $e->eval('
    var f = x => {
        var doubled = x * 2;
        var tripled = x * 3;
        return doubled + tripled;
    };
    f(6)
'));
check('block with if', 'even', $e->eval('
    var classify = n => {
        if (n % 2 === 0) { return "even"; }
        return "odd";
    };
    classify(4)
'));

echo "\n--- 16. Arrow with rest of expression ---\n";
check('arrow + number', 43, $e->eval('(() => 42)() + 1'));
check('arrow in concat', 'result: 42', $e->eval('"result: " + (() => 42)()'));

echo "\n--- 17. Transpiler edge cases ---\n";
$php = $e->transpile('var f = x => y => x + y; f(1)(2)');
check('TR: nested curry', 3, $e->evalTranspiled($php));

$php = $e->transpile('var f = x => x > 0 ? "yes" : "no"; f(1)');
check('TR: ternary body', 'yes', $e->evalTranspiled($php));

$php = $e->transpile('[1,2,3].map(x => x * 2)');
check('TR: map callback', [2, 4, 6], $e->evalTranspiled($php));

$php = $e->transpile('(() => 42)()');
check('TR: IIFE', 42, $e->evalTranspiled($php));

$php = $e->transpile('var f = x => -x; f(5)');
check('TR: unary body', -5, $e->evalTranspiled($php));

$php = $e->transpile('var f = () => [1,2,3]; f()');
check('TR: return array', [1, 2, 3], $e->evalTranspiled($php));

$php = $e->transpile('var f = x => `hello ${x}`; f("world")');
check('TR: template body', 'hello world', $e->evalTranspiled($php));

$php = $e->transpile('var cond = false; var f = cond ? x => x * 2 : x => x * 3; f(5)');
check('TR: arrow in ternary alt', 15, $e->evalTranspiled($php));

$php = $e->transpile('var sum = 0; [1,2,3].forEach(x => { sum = sum + x; }); sum');
check('TR: forEach block body', 6, $e->evalTranspiled($php));

echo "\n=== Results: {$pass}/{$tests} passed ===\n";
if ($pass < $tests) { exit(1); }
