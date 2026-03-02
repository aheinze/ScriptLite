<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class FunctionsTest extends ScriptLiteTestCase
{

    // ═══════════════════ Functions & Closures ═══════════════════

    public function testFunctionDeclarationAndCall(): void
    {
        $this->assertBothBackends('
            function add(a, b) {
                return a + b;
            }
            add(3, 4);
        ', 7);
    }

    public function testClosure(): void
    {
        $this->assertBothBackends('
            function makeCounter() {
                var count = 0;
                function increment() {
                    count = count + 1;
                    return count;
                }
                return increment;
            }
            var counter = makeCounter();
            counter();
            counter();
            counter();
        ', 3);
    }

    public function testClosureCapture(): void
    {
        $this->assertBothBackends('
            function makeAdder(x) {
                function adder(y) {
                    return x + y;
                }
                return adder;
            }
            var add5 = makeAdder(5);
            add5(3);
        ', 8);
    }

    public function testClosureMutatesOuterBinding(): void
    {
        $this->assertBothBackends('
            let x = 1;
            function add() {
                x++;
            }
            add();
            add();
            x;
        ', 3);
    }

    public function testMultipleClosuresOverSameScope(): void
    {
        $this->assertBothBackends('
            function makePair() {
                var value = 0;
                function get() { return value; }
                function set(v) { value = v; }
                return get;
            }
            var getter = makePair();
            getter();
        ', 0);
    }

    public function testFunctionExpression(): void
    {
        $this->assertBothBackends('
            var double = function(x) { return x * 2; };
            double(21);
        ', 42);
    }

    public function testRecursion(): void
    {
        $this->assertBothBackends('
            function factorial(n) {
                if (n <= 1) { return 1; }
                return n * factorial(n - 1);
            }
            factorial(10);
        ', 3628800);
    }

    // ═══════════════════ Scope Isolation ═══════════════════

    public function testFunctionScopeDoesNotLeak(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            function leaky() {
                var secret = 42;
            }
            leaky();
            secret;
        ');
    }

    public function testNestedScopeDoesNotLeak(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            function outer() {
                function inner() {
                    var deep = 100;
                }
                inner();
                deep;
            }
            outer();
        ');
    }

    public function testParameterDoesNotLeakToGlobal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            function foo(x) { return x; }
            foo(5);
            x;
        ');
    }
}
