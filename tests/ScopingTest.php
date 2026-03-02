<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ScopingTest extends ScriptLiteTestCase
{

    // ═══════════════════ Block Scoping (let/const) ═══════════════════

    public function testLetIsBlockScoped(): void
    {
        // let inside a block should NOT be visible outside
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            if (true) {
                let x = 10;
            }
            x;
        ');
    }

    public function testConstIsBlockScoped(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            if (true) {
                const y = 20;
            }
            y;
        ');
    }

    public function testVarLeaksThroughBlock(): void
    {
        // var should still be visible outside the block (function-scoped)
        $this->assertBothBackends('
            if (true) {
                var x = 42;
            }
            x;
        ', 42);
    }

    public function testLetInForLoopIsScoped(): void
    {
        // let in for-loop init should not leak outside
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not defined');
        $this->engine->eval('
            var sum = 0;
            for (let i = 0; i < 5; i = i + 1) {
                sum = sum + i;
            }
            i;
        ');
    }

    public function testVarInForLoopLeaks(): void
    {
        // var in for-loop should still be visible after
        $this->assertBothBackends('
            for (var i = 0; i < 5; i = i + 1) {}
            i;
        ', 5);
    }

    public function testLetShadowingInBlock(): void
    {
        // let in inner block can shadow outer variable
        $this->assertBothBackends('
            let x = 1;
            if (true) {
                let x = 2;
            }
            x;
        ', 1);
    }

    public function testLetInNestedBlocks(): void
    {
        $this->assertBothBackends('
            let result = 0;
            if (true) {
                let a = 10;
                if (true) {
                    let b = 20;
                    result = a + b;
                }
            }
            result;
        ', 30);
    }

    public function testForLoopLetAccumulation(): void
    {
        // for-loop with let should still work correctly for the loop body
        $this->assertBothBackends('
            var sum = 0;
            for (let i = 0; i < 10; i = i + 1) {
                sum = sum + i;
            }
            sum;
        ', 45);
    }
}
