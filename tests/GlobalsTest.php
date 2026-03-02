<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class GlobalsTest extends ScriptLiteTestCase
{

    // ═══════════════════ Array Static Methods ═══════════════════

    public function testArrayIsArray(): void
    {
        $this->assertBothBackends('Array.isArray([1, 2, 3])', true);
        $this->assertBothBackends('Array.isArray([])', true);
    }

    public function testArrayIsArrayFalse(): void
    {
        // VM-only: Array.isArray({}) — transpiler maps {} to [] (both are PHP arrays)
        $this->assertVm('Array.isArray({})', false);
        $this->assertBothBackends('Array.isArray("hello")', false);
        $this->assertBothBackends('Array.isArray(42)', false);
        $this->assertBothBackends('Array.isArray(null)', false);
    }

    public function testArrayFrom(): void
    {
        $this->assertBothBackends('
            var original = [1, 2, 3];
            var copy = Array.from(original);
            original.push(4);
            copy;
        ', [1, 2, 3]);
    }

    public function testArrayOf(): void
    {
        $this->assertBothBackends('Array.of(1, 2, 3)', [1, 2, 3]);
    }

    public function testArrayOfSingle(): void
    {
        $this->assertBothBackends('Array.of(5)', [5]);
    }

    // ═══════════════════ Namespace Globals (console, Math) ═══════════════════

    public function testConsoleLogNamespace(): void
    {
        $this->engine->eval('console.log("hello", "world")');
        self::assertSame("hello world\n", $this->engine->getOutput());
    }

    public function testMathFloorNamespace(): void
    {
        $this->assertBothBackends('Math.floor(3.7)', 3);
    }

    public function testMathCeilNamespace(): void
    {
        $this->assertBothBackends('Math.ceil(3.2)', 4);
    }

    public function testMathAbsNamespace(): void
    {
        $this->assertBothBackends('Math.abs(-5)', 5);
    }

    public function testMathMaxNamespace(): void
    {
        $this->assertBothBackends('Math.max(3, 10)', 10);
    }

    public function testMathMinNamespace(): void
    {
        $this->assertBothBackends('Math.min(3, 10)', 3);
    }

    public function testMathRound(): void
    {
        $this->assertBothBackends('Math.round(3.7)', 4);
        $this->assertBothBackends('Math.round(3.2)', 3);
    }

    public function testMathPI(): void
    {
        $result = $this->engine->eval('Math.PI');
        self::assertEqualsWithDelta(3.14159, $result, 0.001);
    }
}
