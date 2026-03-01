<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class DoWhileTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

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
        self::assertSame(1, $this->engine->eval($code));
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
        self::assertSame(55, $this->engine->eval($code));
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
        self::assertTrue($this->engine->eval($code));
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
        self::assertSame(5, $this->engine->eval($code));
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
        self::assertSame(5, $this->engine->eval($code));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerBodyExecutesOnce(): void
    {
        $php = $this->engine->transpile('
            var x = 0;
            do {
                x = x + 1;
            } while (false);
            x
        ');
        self::assertSame(1, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerCounterLoop(): void
    {
        $php = $this->engine->transpile('
            var sum = 0;
            var i = 1;
            do {
                sum = sum + i;
                i = i + 1;
            } while (i <= 10);
            sum
        ');
        self::assertSame(55, $this->engine->evalTranspiled($php));
    }
}
