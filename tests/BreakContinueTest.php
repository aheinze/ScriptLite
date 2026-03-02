<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class BreakContinueTest extends ScriptLiteTestCase
{

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
        $this->assertBothBackends($code, 5);
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
        $this->assertBothBackends($code, 3);
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
        $this->assertBothBackends($code, 10);
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
        $this->assertBothBackends($code, 25);
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
        $this->assertBothBackends($code, 25);
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
        $this->assertBothBackends($code, 8);
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
        $this->assertBothBackends($code, 6);
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
        $this->assertBothBackends($code, 9);
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
        $this->assertBothBackends($code, 3);
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
        $this->assertBothBackends($code, 25);
    }
}
