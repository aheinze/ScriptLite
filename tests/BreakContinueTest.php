<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class BreakContinueTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ break in while ═══════════════════

    public function testBreakInWhile(): void
    {
        $code = '
            var i = 0;
            while (true) {
                if (i === 5) break;
                i = i + 1;
            }
            i
        ';
        self::assertSame(5, $this->engine->eval($code));
    }

    public function testBreakExitsImmediately(): void
    {
        $code = '
            var sum = 0;
            var i = 0;
            while (i < 100) {
                if (i === 3) break;
                sum = sum + i;
                i = i + 1;
            }
            sum
        ';
        // 0 + 1 + 2 = 3
        self::assertSame(3, $this->engine->eval($code));
    }

    // ═══════════════════ break in for ═══════════════════

    public function testBreakInFor(): void
    {
        $code = '
            var sum = 0;
            for (var i = 0; i < 10; i = i + 1) {
                if (i === 5) break;
                sum = sum + i;
            }
            sum
        ';
        // 0+1+2+3+4 = 10
        self::assertSame(10, $this->engine->eval($code));
    }

    // ═══════════════════ continue in while ═══════════════════

    public function testContinueInWhile(): void
    {
        $code = '
            var sum = 0;
            var i = 0;
            while (i < 10) {
                i = i + 1;
                if (i % 2 === 0) continue;
                sum = sum + i;
            }
            sum
        ';
        // 1+3+5+7+9 = 25
        self::assertSame(25, $this->engine->eval($code));
    }

    // ═══════════════════ continue in for ═══════════════════

    public function testContinueInFor(): void
    {
        $code = '
            var sum = 0;
            for (var i = 0; i < 10; i = i + 1) {
                if (i % 2 === 0) continue;
                sum = sum + i;
            }
            sum
        ';
        // 1+3+5+7+9 = 25
        self::assertSame(25, $this->engine->eval($code));
    }

    public function testContinueExecutesUpdate(): void
    {
        // Critical: continue in for loop must execute the update expression
        $code = '
            var result = 0;
            for (var i = 0; i < 5; i = i + 1) {
                if (i === 2) continue;
                result = result + i;
            }
            result
        ';
        // 0+1+3+4 = 8 (skips i=2)
        self::assertSame(8, $this->engine->eval($code));
    }

    // ═══════════════════ Nested loops ═══════════════════

    public function testBreakOnlyExitsInnermost(): void
    {
        $code = '
            var count = 0;
            for (var i = 0; i < 3; i = i + 1) {
                for (var j = 0; j < 10; j = j + 1) {
                    if (j === 2) break;
                    count = count + 1;
                }
            }
            count
        ';
        // Inner loop runs 2 iterations (j=0,1) × 3 outer iterations = 6
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testContinueOnlyAffectsInnermost(): void
    {
        $code = '
            var count = 0;
            for (var i = 0; i < 3; i = i + 1) {
                for (var j = 0; j < 4; j = j + 1) {
                    if (j === 1) continue;
                    count = count + 1;
                }
            }
            count
        ';
        // Inner: 3 of 4 iterations counted × 3 outer = 9
        self::assertSame(9, $this->engine->eval($code));
    }

    // ═══════════════════ break in do-while ═══════════════════

    public function testBreakInDoWhile(): void
    {
        $code = '
            var i = 0;
            do {
                if (i === 3) break;
                i = i + 1;
            } while (true);
            i
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    // ═══════════════════ continue in do-while ═══════════════════

    public function testContinueInDoWhile(): void
    {
        $code = '
            var sum = 0;
            var i = 0;
            do {
                i = i + 1;
                if (i % 2 === 0) continue;
                sum = sum + i;
            } while (i < 10);
            sum
        ';
        // 1+3+5+7+9 = 25
        self::assertSame(25, $this->engine->eval($code));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerBreakInFor(): void
    {
        $php = $this->engine->transpile('
            var sum = 0;
            for (var i = 0; i < 10; i = i + 1) {
                if (i === 5) break;
                sum = sum + i;
            }
            sum
        ');
        self::assertSame(10, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerContinueInFor(): void
    {
        $php = $this->engine->transpile('
            var sum = 0;
            for (var i = 0; i < 10; i = i + 1) {
                if (i % 2 === 0) continue;
                sum = sum + i;
            }
            sum
        ');
        self::assertSame(25, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerBreakInWhile(): void
    {
        $php = $this->engine->transpile('
            var i = 0;
            while (true) {
                if (i === 5) break;
                i = i + 1;
            }
            i
        ');
        self::assertSame(5, $this->engine->evalTranspiled($php));
    }
}
