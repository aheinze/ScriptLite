<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class DoWhileTest extends ScriptLiteTestCase
{

    // ═══════════════════ Basic behavior ═══════════════════

    public function testBodyExecutesAtLeastOnce(): void
    {
        $code = '
            var x = 0;
            do {
                x = x + 1;
            } while (false);
            x
        ';
        $this->assertBothBackends($code, 1);
    }

    public function testCounterLoop(): void
    {
        $code = '
            var sum = 0;
            var i = 1;
            do {
                sum = sum + i;
                i = i + 1;
            } while (i <= 10);
            sum
        ';
        // 1+2+...+10 = 55
        $this->assertBothBackends($code, 55);
    }

    public function testConditionCheckedAfterBody(): void
    {
        // Even though condition is false, body executes once
        $code = '
            var ran = false;
            do {
                ran = true;
            } while (false);
            ran
        ';
        $this->assertBothBackends($code, true);
    }

    public function testMultipleIterations(): void
    {
        $code = '
            var i = 0;
            do {
                i = i + 1;
            } while (i < 5);
            i
        ';
        $this->assertBothBackends($code, 5);
    }

    // ═══════════════════ With other control flow ═══════════════════

    public function testDoWhileWithIf(): void
    {
        $code = '
            var evens = 0;
            var i = 0;
            do {
                if (i % 2 === 0) evens = evens + 1;
                i = i + 1;
            } while (i < 10);
            evens
        ';
        // 0,2,4,6,8 = 5 evens
        $this->assertBothBackends($code, 5);
    }
}
