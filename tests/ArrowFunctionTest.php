<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class ArrowFunctionTest extends ScriptLiteTestCase
{

    // ═══════════════════ Expression body ═══════════════════

    public function testSingleParamNoParens(): void
    {
        $this->assertBothBackends('var f = x => x * 2; f(5)', 10);
    }

    public function testSingleParamWithParens(): void
    {
        $this->assertBothBackends('var f = (x) => x * 2; f(5)', 10);
    }

    public function testMultipleParams(): void
    {
        $this->assertBothBackends('var f = (a, b) => a + b; f(3, 4)', 7);
    }

    public function testNoParams(): void
    {
        $this->assertBothBackends('var f = () => 42; f()', 42);
    }

    public function testExpressionBodyString(): void
    {
        $this->assertBothBackends('var f = (a, b) => a + " " + b; f("hello", "world")', 'hello world');
    }

    // ═══════════════════ Block body ═══════════════════

    public function testBlockBody(): void
    {
        $this->assertBothBackends('var f = x => { return x * 2; }; f(5)', 10);
    }

    public function testBlockBodyMultipleParams(): void
    {
        $this->assertBothBackends('
            var f = (a, b) => {
                var sum = a + b;
                return sum * 2;
            };
            f(2, 4)
        ', 12);
    }

    public function testBlockBodyNoReturn(): void
    {
        // Block body with no return should return undefined (null in PHP)
        $this->assertBothBackends('var f = () => {}; f()', null);
    }

    // ═══════════════════ Closure capture ═══════════════════

    public function testClosureCapture(): void
    {
        $this->assertBothBackends('
            var multiplier = 3;
            var f = x => x * multiplier;
            f(5)
        ', 15);
    }

    public function testClosureCaptureEnclosing(): void
    {
        $this->assertBothBackends('
            function make() {
                var base = 10;
                return () => base;
            }
            make()()
        ', 10);
    }

    // ═══════════════════ Nested arrows ═══════════════════

    public function testNestedArrows(): void
    {
        $this->assertBothBackends('
            var f = x => y => x + y;
            f(1)(2)
        ', 3);
    }

    public function testNestedArrowsThreeDeep(): void
    {
        $this->assertBothBackends('
            var f = a => b => c => a + b + c;
            f(1)(2)(3)
        ', 6);
    }

    // ═══════════════════ As arguments ═══════════════════

    public function testAsCallbackArgument(): void
    {
        $this->assertBothBackends('
            function apply(fn, val) { return fn(val); }
            apply(x => x * 2, 3)
        ', 6);
    }

    public function testMapCallback(): void
    {
        $this->assertBothBackends('[1, 2, 3].map(x => x * 2)', [2, 4, 6]);
    }

    public function testFilterCallback(): void
    {
        $this->assertBothBackends('[1, 2, 3, 4, 5].filter(x => x > 3)', [4, 5]);
    }

    // ═══════════════════ With operators ═══════════════════

    public function testWithTernary(): void
    {
        $this->assertBothBackends('var f = x => x > 0 ? "yes" : "no"; f(1)', 'yes');
    }

    public function testWithLogicalOr(): void
    {
        $this->assertBothBackends('var f = x => x || "default"; f("")', 'default');
    }

    public function testWithLogicalAnd(): void
    {
        $this->assertBothBackends('var f = x => x && true; f(false)', false);
    }

    // ═══════════════════ IIFE ═══════════════════

    public function testImmediatelyInvoked(): void
    {
        $this->assertBothBackends('(x => x * 2)(21)', 42);
    }

    public function testImmediatelyInvokedNoParams(): void
    {
        $this->assertBothBackends('(() => 99)()', 99);
    }

    // ═══════════════════ Variable assignment ═══════════════════

    public function testAssignedToVar(): void
    {
        $this->assertBothBackends('var double = x => x * 2; double(3)', 6);
    }

    public function testAssignedToLet(): void
    {
        $this->assertBothBackends('let square = x => x * x; square(3)', 9);
    }

    public function testAssignedToConst(): void
    {
        $this->assertBothBackends('const cube = x => x * x * x; cube(2)', 8);
    }

    // ═══════════════════ Return arrow from function ═══════════════════

    public function testReturnedFromFunction(): void
    {
        $this->assertBothBackends('
            function makeAdder(n) {
                return x => x + n;
            }
            var add5 = makeAdder(5);
            add5(10)
        ', 15);
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspiledSingleParam(): void
    {
        $php = $this->engine->transpile('var f = x => x * 2; f(5)');
        self::assertSame(10, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledMultipleParams(): void
    {
        $php = $this->engine->transpile('var f = (a, b) => a + b; f(3, 4)');
        self::assertSame(7, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledNoParams(): void
    {
        $php = $this->engine->transpile('var f = () => 42; f()');
        self::assertSame(42, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledBlockBody(): void
    {
        $php = $this->engine->transpile('var f = x => { return x * 2; }; f(5)');
        self::assertSame(10, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledClosureCapture(): void
    {
        $php = $this->engine->transpile('var a = 10; var f = x => x + a; f(5)');
        self::assertSame(15, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledNested(): void
    {
        $php = $this->engine->transpile('var f = x => y => x + y; f(1)(2)');
        self::assertSame(3, $this->engine->evalTranspiled($php));
    }

    public function testTranspiledAsCallback(): void
    {
        $php = $this->engine->transpile('[1, 2, 3].map(x => x * 2)');
        self::assertSame([2, 4, 6], $this->engine->evalTranspiled($php));
    }

    public function testTranspiledWithTernary(): void
    {
        $php = $this->engine->transpile('var f = x => x > 0 ? "yes" : "no"; f(1)');
        self::assertSame('yes', $this->engine->evalTranspiled($php));
    }

    public function testTranspiledIIFE(): void
    {
        $php = $this->engine->transpile('(x => x * 2)(21)');
        self::assertSame(42, $this->engine->evalTranspiled($php));
    }
}
