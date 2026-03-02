<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ControlFlowTest extends ScriptLiteTestCase
{

    // ═══════════════════ Control Flow ═══════════════════

    public function testIfElse(): void
    {
        $this->assertBothBackends('var r = 0; if (true) { r = 1; } else { r = 2; } r', 1);
        $this->assertBothBackends('var r = 0; if (false) { r = 1; } else { r = 2; } r', 2);
    }

    public function testWhileLoop(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            var i = 1;
            while (i <= 100) {
                sum = sum + i;
                i = i + 1;
            }
            sum;
        ', 5050);
    }

    public function testForLoop(): void
    {
        $this->assertBothBackends('
            var sum = 0;
            for (var i = 0; i < 10; i = i + 1) {
                sum = sum + i;
            }
            sum;
        ', 45);
    }

    // ═══════════════════ Fibonacci (Integration) ═══════════════════

    public function testFibonacciRecursive(): void
    {
        $this->assertBothBackends('
            function fib(n) {
                if (n <= 1) { return n; }
                return fib(n - 1) + fib(n - 2);
            }
            fib(10);
        ', 55);
    }

    public function testFibonacciIterative(): void
    {
        $this->assertBothBackends('
            function fib(n) {
                if (n <= 1) { return n; }
                var a = 0;
                var b = 1;
                for (var i = 2; i <= n; i = i + 1) {
                    var temp = b;
                    b = a + b;
                    a = temp;
                }
                return b;
            }
            fib(20);
        ', 6765);
    }

    public function testFibonacciWithClosure(): void
    {
        $this->assertBothBackends('
            function memoFib() {
                var cache_0 = 0;
                var cache_1 = 1;
                var cache_2 = -1;
                var cache_3 = -1;
                var cache_4 = -1;
                var cache_5 = -1;
                var cache_6 = -1;
                var cache_7 = -1;
                var cache_8 = -1;
                var cache_9 = -1;
                var cache_10 = -1;

                function compute(n) {
                    if (n == 0) { return cache_0; }
                    if (n == 1) { return cache_1; }
                    if (n <= 1) { return n; }
                    var a = 0;
                    var b = 1;
                    for (var i = 2; i <= n; i = i + 1) {
                        var temp = b;
                        b = a + b;
                        a = temp;
                    }
                    return b;
                }

                return compute;
            }

            var fib = memoFib();
            fib(10);
        ', 55);
    }
}
