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

    public function testMathExtendedMethods(): void
    {
        $vm = $this->engine->eval('Math.sqrt(9)');
        $transpiled = $this->engine->transpileAndEval('Math.sqrt(9)');
        self::assertEqualsWithDelta(3.0, $vm, 0.0);
        self::assertEqualsWithDelta(3.0, $transpiled, 0.0);

        $vm = $this->engine->eval('Math.pow(2, 10)');
        $transpiled = $this->engine->transpileAndEval('Math.pow(2, 10)');
        self::assertEqualsWithDelta(1024.0, $vm, 0.0);
        self::assertEqualsWithDelta(1024.0, $transpiled, 0.0);

        $vm = $this->engine->eval('Math.sin(0)');
        $transpiled = $this->engine->transpileAndEval('Math.sin(0)');
        self::assertEqualsWithDelta(0.0, $vm, 0.0);
        self::assertEqualsWithDelta(0.0, $transpiled, 0.0);

        $vm = $this->engine->eval('Math.cos(0)');
        $transpiled = $this->engine->transpileAndEval('Math.cos(0)');
        self::assertEqualsWithDelta(1.0, $vm, 0.0);
        self::assertEqualsWithDelta(1.0, $transpiled, 0.0);

        $vm = $this->engine->eval('Math.log(Math.E)');
        $transpiled = $this->engine->transpileAndEval('Math.log(Math.E)');
        self::assertEqualsWithDelta(1.0, $vm, 1e-12);
        self::assertEqualsWithDelta(1.0, $transpiled, 1e-12);

        $this->assertBothBackends('Math.trunc(3.9)', 3);
        $this->assertBothBackends('Math.sign(-12)', -1);
        $this->assertBothBackends('Math.sign(0)', 0);
    }

    public function testMathExtendedConstants(): void
    {
        $this->assertBothBackends('Math.E', M_E);
        $this->assertBothBackends('Math.LN2', M_LN2);
        $this->assertBothBackends('Math.LN10', M_LN10);
        $this->assertBothBackends('Math.LOG2E', M_LOG2E);
        $this->assertBothBackends('Math.LOG10E', M_LOG10E);
        $this->assertBothBackends('Math.SQRT1_2', M_SQRT1_2);
        $this->assertBothBackends('Math.SQRT2', M_SQRT2);
    }

    // ═══════════════════ URI Globals ═══════════════════

    public function testEncodeURIComponent(): void
    {
        $this->assertBothBackends('encodeURIComponent("a b/c?d=e")', 'a%20b%2Fc%3Fd%3De');
    }

    public function testDecodeURIComponent(): void
    {
        $this->assertBothBackends('decodeURIComponent("a%20b%2Fc%3Fd%3De")', 'a b/c?d=e');
    }

    public function testEncodeURI(): void
    {
        $this->assertBothBackends(
            'encodeURI("https://example.com/a b?x=1&y=2")',
            'https://example.com/a%20b?x=1&y=2'
        );
    }

    public function testDecodeURI(): void
    {
        $this->assertBothBackends(
            'decodeURI("https://example.com/a%20b?x=1&y=2")',
            'https://example.com/a b?x=1&y=2'
        );
    }
}
