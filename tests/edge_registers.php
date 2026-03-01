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

echo "=== Edge Case Tests: Register File Optimization ===\n\n";

// ─── 1. Basic var in register ───
echo "--- 1. Basic var in register ---\n";
check('var declaration', 42, $e->eval('var x = 42; x'));
check('var reassignment', 10, $e->eval('var x = 5; x = 10; x'));
check('var multiple', 15, $e->eval('var a = 5; var b = 10; a + b'));
check('var no initializer', null, $e->eval('var x; x')); // undefined → toPhp → null

// ─── 2. Parameters in register ───
echo "\n--- 2. Parameters in register ---\n";
check('single param', 6, $e->eval('function double(x) { return x * 2; } double(3)'));
check('two params', 7, $e->eval('function add(a, b) { return a + b; } add(3, 4)'));
check('unused param', 5, $e->eval('function f(a, b) { return a; } f(5, 99)'));
check('missing arg → undefined', null, $e->eval('function f(a, b) { return b; } f(5)')); // undefined → toPhp → null

// ─── 3. Captured var stays in environment ───
echo "\n--- 3. Captured var stays in environment ---\n";
check('closure captures var', 10, $e->eval('
    function make() {
        var x = 10;
        return function() { return x; };
    }
    make()()
'));
check('closure captures mutated var', 20, $e->eval('
    function make() {
        var x = 10;
        x = 20;
        return function() { return x; };
    }
    make()()
'));
check('closure reads updated var', 99, $e->eval('
    function make() {
        var x = 1;
        var get = function() { return x; };
        x = 99;
        return get();
    }
    make()
'));

// ─── 4. Captured param stays in environment ───
echo "\n--- 4. Captured param stays in environment ---\n";
check('param captured by closure', 42, $e->eval('
    function make(val) {
        return function() { return val; };
    }
    make(42)()
'));
check('param not captured: register path', 100, $e->eval('
    function square(n) { return n * n; }
    square(10)
'));

// ─── 5. Mixed captured/uncaptured params ───
echo "\n--- 5. Mixed captured/uncaptured params ---\n";
check('mixed params', 30, $e->eval('
    function f(a, b) {
        var inner = function() { return b; };
        return a + inner();
    }
    f(10, 20)
'));

// ─── 6. Var hoisting across blocks ───
echo "\n--- 6. Var hoisting across blocks ---\n";
check('var in if block', 5, $e->eval('
    function f() {
        if (true) { var x = 5; }
        return x;
    }
    f()
'));
check('var in else block', 7, $e->eval('
    function f() {
        if (false) { var x = 3; } else { var x = 7; }
        return x;
    }
    f()
'));
check('var in while body', 9, $e->eval('
    function f() {
        var i = 0;
        while (i < 10) { var x = i; i = i + 1; }
        return x;
    }
    f()
'));

// ─── 7. Var in for loop ───
echo "\n--- 7. Var in for loop ---\n";
check('for var i', 10, $e->eval('
    function f() {
        var sum = 0;
        for (var i = 0; i < 5; i = i + 1) {
            sum = sum + i;
        }
        return sum;
    }
    f()
'));
check('for var visible after loop', 5, $e->eval('
    function f() {
        for (var i = 0; i < 5; i = i + 1) {}
        return i;
    }
    f()
'));

// ─── 8. Compound assignment with registers ───
echo "\n--- 8. Compound assignment ---\n";
check('+=', 15, $e->eval('function f() { var x = 10; x += 5; return x; } f()'));
check('-=', 5, $e->eval('function f() { var x = 10; x -= 5; return x; } f()'));
check('*=', 50, $e->eval('function f() { var x = 10; x *= 5; return x; } f()'));
check('/=', 2, $e->eval('function f() { var x = 10; x /= 5; return x; } f()'));

// ─── 9. Function declarations in register ───
echo "\n--- 9. Function declarations in register ---\n";
check('function decl in register', 25, $e->eval('
    function outer() {
        function square(n) { return n * n; }
        return square(5);
    }
    outer()
'));
check('function decl hoisted', 10, $e->eval('
    function outer() {
        var result = double(5);
        function double(x) { return x * 2; }
        return result;
    }
    outer()
'));

// ─── 10. Recursive function (captures self) ───
echo "\n--- 10. Recursive function ---\n";
check('recursive factorial', 120, $e->eval('
    function fact(n) {
        if (n <= 1) { return 1; }
        return n * fact(n - 1);
    }
    fact(5)
'));
check('recursive fib', 8, $e->eval('
    function fib(n) {
        if (n <= 1) { return n; }
        return fib(n - 1) + fib(n - 2);
    }
    fib(6)
'));

// ─── 11. Nested function calls ───
echo "\n--- 11. Nested function calls ---\n";
check('nested calls', 20, $e->eval('
    function double(x) { return x * 2; }
    function addOne(x) { return x + 1; }
    double(addOne(9))
'));
check('deep nesting', 64, $e->eval('
    function double(x) { return x * 2; }
    double(double(double(8)))
'));

// ─── 12. Constructor (new) with registers ───
echo "\n--- 12. Constructor with registers ---\n";
check('new with register params', 30, $e->eval('
    function Point(x, y) {
        this.x = x;
        this.y = y;
        this.sum = x + y;
    }
    var p = new Point(10, 20);
    p.sum
'));

// ─── 13. Callback (invokeFunction) with registers ───
echo "\n--- 13. Callback via invokeFunction ---\n";
check('map with register var', '2,4,6', $e->eval('
    var arr = [1, 2, 3];
    var result = arr.map(function(x) { return x * 2; });
    result.join(",")
'));
check('filter with register var', '2,4', $e->eval('
    var arr = [1, 2, 3, 4, 5];
    var result = arr.filter(function(x) { return x % 2 === 0; });
    result.join(",")
'));
check('forEach with register var', 15, $e->eval('
    var sum = 0;
    var arr = [1, 2, 3, 4, 5];
    arr.forEach(function(x) { sum = sum + x; });
    sum
'));

// ─── 14. Assignment expression returns value ───
echo "\n--- 14. Assignment as expression ---\n";
check('assign expr value', 42, $e->eval('function f() { var x; return x = 42; } f()'));
check('compound assign expr value', 15, $e->eval('function f() { var x = 10; return x += 5; } f()'));

// ─── 15. Top-level var (main script) ───
echo "\n--- 15. Top-level var in main script ---\n";
check('top-level var', 100, $e->eval('var x = 100; x'));
check('top-level multiple vars', 300, $e->eval('var a = 100; var b = 200; a + b'));
check('top-level var compound assign', 15, $e->eval('var x = 10; x += 5; x'));

// ─── 16. let/const NOT in registers (block scoping preserved) ───
echo "\n--- 16. let/const scoping preserved ---\n";
check('let block scoped - var carries value', 5, $e->eval('
    var outer;
    { let x = 5; outer = x; }
    outer
'));
check('let block scoped value', 5, $e->eval('
    var result;
    { let x = 5; result = x; }
    result
'));

// ─── 17. Var re-declaration (same name) ───
echo "\n--- 17. Var re-declaration ---\n";
check('var re-decl same scope', 20, $e->eval('
    function f() {
        var x = 10;
        var x = 20;
        return x;
    }
    f()
'));
check('var re-decl nested block', 30, $e->eval('
    function f() {
        var x = 10;
        { var x = 30; }
        return x;
    }
    f()
'));

// ─── 18. Uninitialized register reads undefined ───
echo "\n--- 18. Uninitialized register is undefined ---\n";
check('typeof uninitialized var', 'undefined', $e->eval('
    function f() {
        var x;
        return typeof x;
    }
    f()
'));
check('uninitialized var is undefined', null, $e->eval('
    function f() {
        var x;
        return x;
    }
    f()
')); // undefined → toPhp → null

// ─── 19. Multiple functions sharing nothing ───
echo "\n--- 19. Independent functions ---\n";
check('two independent functions', 32, $e->eval(' // 10+15+4+3=32
    function a(x) { return x * 2; }
    function b(x) { return x * 3; }
    a(5) + b(5) + a(2) + b(1)
'));

// ─── 20. Arrow functions with registers ───
echo "\n--- 20. Arrow functions with registers ---\n";
check('arrow param in register', 25, $e->eval('var f = x => x * 5; f(5)'));
check('arrow two params', 12, $e->eval('var f = (a, b) => a + b; f(5, 7)'));
check('arrow closure capture', 10, $e->eval('
    function make(x) {
        return () => x;
    }
    make(10)()
'));

// ─── Summary ───
echo "\n=== Results: {$pass}/{$tests} passed ===\n";
if ($pass < $tests) {
    exit(1);
}
