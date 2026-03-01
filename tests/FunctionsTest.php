<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Functions & Closures ═══════════════════

    public function testFunctionDeclarationAndCall(): void
    {
        $result = $this->engine->eval('
            function add(a, b) {
                return a + b;
            }
            add(3, 4);
        ');
        self::assertSame(7, $result);
    }

    public function testClosure(): void
    {
        $result = $this->engine->eval('
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
        ');
        self::assertSame(3, $result);
    }

    public function testClosureCapture(): void
    {
        $result = $this->engine->eval('
            function makeAdder(x) {
                function adder(y) {
                    return x + y;
                }
                return adder;
            }
            var add5 = makeAdder(5);
            add5(3);
        ');
        self::assertSame(8, $result);
    }

    public function testMultipleClosuresOverSameScope(): void
    {
        $result = $this->engine->eval('
            function makePair() {
                var value = 0;
                function get() { return value; }
                function set(v) { value = v; }
                return get;
            }
            var getter = makePair();
            getter();
        ');
        self::assertSame(0, $result);
    }

    public function testFunctionExpression(): void
    {
        $result = $this->engine->eval('
            var double = function(x) { return x * 2; };
            double(21);
        ');
        self::assertSame(42, $result);
    }

    public function testRecursion(): void
    {
        $result = $this->engine->eval('
            function factorial(n) {
                if (n <= 1) { return 1; }
                return n * factorial(n - 1);
            }
            factorial(10);
        ');
        self::assertSame(3628800, $result);
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
