<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ScopingTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

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
        $result = $this->engine->eval('
            if (true) {
                var x = 42;
            }
            x;
        ');
        self::assertSame(42, $result);
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
        $result = $this->engine->eval('
            for (var i = 0; i < 5; i = i + 1) {}
            i;
        ');
        self::assertSame(5, $result);
    }

    public function testLetShadowingInBlock(): void
    {
        // let in inner block can shadow outer variable
        $result = $this->engine->eval('
            let x = 1;
            if (true) {
                let x = 2;
            }
            x;
        ');
        self::assertSame(1, $result);
    }

    public function testLetInNestedBlocks(): void
    {
        $result = $this->engine->eval('
            let result = 0;
            if (true) {
                let a = 10;
                if (true) {
                    let b = 20;
                    result = a + b;
                }
            }
            result;
        ');
        self::assertSame(30, $result);
    }

    public function testForLoopLetAccumulation(): void
    {
        // for-loop with let should still work correctly for the loop body
        $result = $this->engine->eval('
            var sum = 0;
            for (let i = 0; i < 10; i = i + 1) {
                sum = sum + i;
            }
            sum;
        ');
        self::assertSame(45, $result);
    }
}
