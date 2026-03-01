<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class ArrowFunctionTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Expression body ═══════════════════

    public function testSingleParamNoParens(): void
    {
        self::assertSame(10, $this->engine->eval('var f = x => x * 2; f(5)'));
    }

    public function testSingleParamWithParens(): void
    {
        self::assertSame(10, $this->engine->eval('var f = (x) => x * 2; f(5)'));
    }

    public function testMultipleParams(): void
    {
        self::assertSame(7, $this->engine->eval('var f = (a, b) => a + b; f(3, 4)'));
    }

    public function testNoParams(): void
    {
        self::assertSame(42, $this->engine->eval('var f = () => 42; f()'));
    }

    public function testExpressionBodyString(): void
    {
        self::assertSame('hello world', $this->engine->eval('var f = (a, b) => a + " " + b; f("hello", "world")'));
    }

    // ═══════════════════ Block body ═══════════════════

    public function testBlockBody(): void
    {
        self::assertSame(10, $this->engine->eval('var f = x => { return x * 2; }; f(5)'));
    }

    public function testBlockBodyMultipleParams(): void
    {
        self::assertSame(12, $this->engine->eval('
            var f = (a, b) => {
                var sum = a + b;
                return sum * 2;
            };
            f(2, 4)
        '));
    }

    public function testBlockBodyNoReturn(): void
    {
        // Block body with no return should return undefined (null in PHP)
        self::assertNull($this->engine->eval('var f = () => {}; f()'));
    }

    // ═══════════════════ Closure capture ═══════════════════

    public function testClosureCapture(): void
    {
        self::assertSame(15, $this->engine->eval('
            var multiplier = 3;
            var f = x => x * multiplier;
            f(5)
        '));
    }

    public function testClosureCaptureEnclosing(): void
    {
        self::assertSame(10, $this->engine->eval('
            function make() {
                var base = 10;
                return () => base;
            }
            make()()
        '));
    }

    // ═══════════════════ Nested arrows ═══════════════════

    public function testNestedArrows(): void
    {
        self::assertSame(3, $this->engine->eval('
            var f = x => y => x + y;
            f(1)(2)
        '));
    }

    public function testNestedArrowsThreeDeep(): void
    {
        self::assertSame(6, $this->engine->eval('
            var f = a => b => c => a + b + c;
            f(1)(2)(3)
        '));
    }

    // ═══════════════════ As arguments ═══════════════════

    public function testAsCallbackArgument(): void
    {
        self::assertSame(6, $this->engine->eval('
            function apply(fn, val) { return fn(val); }
            apply(x => x * 2, 3)
        '));
    }

    public function testMapCallback(): void
    {
        $result = $this->engine->eval('[1, 2, 3].map(x => x * 2)');
        self::assertSame([2, 4, 6], $result);
    }

    public function testFilterCallback(): void
    {
        $result = $this->engine->eval('[1, 2, 3, 4, 5].filter(x => x > 3)');
        self::assertSame([4, 5], $result);
    }

    // ═══════════════════ With operators ═══════════════════

    public function testWithTernary(): void
    {
        self::assertSame('yes', $this->engine->eval('var f = x => x > 0 ? "yes" : "no"; f(1)'));
    }

    public function testWithLogicalOr(): void
    {
        self::assertSame('default', $this->engine->eval('var f = x => x || "default"; f("")'));
    }

    public function testWithLogicalAnd(): void
    {
        self::assertSame(false, $this->engine->eval('var f = x => x && true; f(false)'));
    }

    // ═══════════════════ IIFE ═══════════════════

    public function testImmediatelyInvoked(): void
    {
        self::assertSame(42, $this->engine->eval('(x => x * 2)(21)'));
    }

    public function testImmediatelyInvokedNoParams(): void
    {
        self::assertSame(99, $this->engine->eval('(() => 99)()'));
    }

    // ═══════════════════ Variable assignment ═══════════════════

    public function testAssignedToVar(): void
    {
        self::assertSame(6, $this->engine->eval('var double = x => x * 2; double(3)'));
    }

    public function testAssignedToLet(): void
    {
        self::assertSame(9, $this->engine->eval('let square = x => x * x; square(3)'));
    }

    public function testAssignedToConst(): void
    {
        self::assertSame(8, $this->engine->eval('const cube = x => x * x * x; cube(2)'));
    }

    // ═══════════════════ Return arrow from function ═══════════════════

    public function testReturnedFromFunction(): void
    {
        self::assertSame(15, $this->engine->eval('
            function makeAdder(n) {
                return x => x + n;
            }
            var add5 = makeAdder(5);
            add5(10)
        '));
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
