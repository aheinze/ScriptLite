<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

class SpreadRestTest extends ScriptLiteTestCase
{

    // ═══════════════════ Array spread ═══════════════════

    public function testArraySpreadCopy(): void
    {
        $this->assertBothBackends('
            var a = [1, 2, 3];
            var b = [...a];
            b.length
        ', 3);
    }

    public function testArraySpreadCopyValues(): void
    {
        $this->assertBothBackends('
            var a = [1, 2, 3];
            var b = [...a];
            b[0] + b[1] + b[2]
        ', 6);
    }

    public function testArraySpreadMerge(): void
    {
        $this->assertBothBackends('
            var a = [2, 3];
            var b = [1, ...a, 4];
            b.length
        ', 4);
    }

    public function testArraySpreadMergeValues(): void
    {
        $this->assertBothBackends('
            var a = [2, 3];
            var b = [1, ...a, 4];
            b[0] + b[1] + b[2] + b[3]
        ', 10);
    }

    public function testArraySpreadMultiple(): void
    {
        $this->assertBothBackends('
            var a = [1, 2];
            var b = [3, 4];
            var c = [...a, ...b];
            c.length
        ', 4);
    }

    public function testArraySpreadMultipleValues(): void
    {
        $this->assertBothBackends('
            var a = [1, 2];
            var b = [3, 4];
            var c = [...a, ...b];
            c[0] + c[1] + c[2] + c[3]
        ', 10);
    }

    public function testArraySpreadString(): void
    {
        $this->assertBothBackends('
            var a = [..."hi"];
            a.length
        ', 2);
    }

    public function testArraySpreadStringValues(): void
    {
        $this->assertBothBackends('
            var a = [..."hi"];
            a[0] + a[1]
        ', 'hi');
    }

    public function testArraySpreadEmpty(): void
    {
        $this->assertBothBackends('
            var a = [];
            var b = [1, ...a, 2];
            b.length
        ', 2);
    }

    // ═══════════════════ Call spread ═══════════════════

    public function testCallSpreadBasic(): void
    {
        $this->assertBothBackends('
            function add(a, b, c) { return a + b + c; }
            var args = [1, 2, 3];
            add(...args)
        ', 6);
    }

    public function testCallSpreadMixed(): void
    {
        $this->assertBothBackends('
            function add(a, b, c, d) { return a + b + c + d; }
            var rest = [3, 4];
            add(1, 2, ...rest)
        ', 10);
    }

    public function testCallSpreadMultiple(): void
    {
        $this->assertBothBackends('
            function sum(a, b, c, d) { return a + b + c + d; }
            var x = [1, 2];
            var y = [3, 4];
            sum(...x, ...y)
        ', 10);
    }

    // ═══════════════════ new spread ═══════════════════

    public function testNewSpread(): void
    {
        $this->assertBothBackends('
            function Point(x, y) {
                this.x = x;
                this.y = y;
            }
            var args = [10, 20];
            var p = new Point(...args);
            p.x + p.y
        ', 30);
    }

    // ═══════════════════ Rest parameters ═══════════════════

    public function testRestParamCollectsAll(): void
    {
        $this->assertBothBackends('
            function f(...args) { return args.length; }
            f(1, 2, 3)
        ', 3);
    }

    public function testRestParamValues(): void
    {
        $this->assertBothBackends('
            function f(...args) { return args[0] + args[1] + args[2]; }
            f(10, 20, 30)
        ', 60);
    }

    public function testRestParamWithRegular(): void
    {
        $this->assertBothBackends('
            function f(a, b, ...rest) { return rest.length; }
            f(1, 2, 3, 4, 5)
        ', 3);
    }

    public function testRestParamWithRegularValues(): void
    {
        $this->assertBothBackends('
            function f(a, b, ...rest) { return a + rest[0] + rest[1]; }
            f(1, 2, 3, 4)
        ', 8);
    }

    public function testRestParamEmpty(): void
    {
        $this->assertBothBackends('
            function f(a, b, ...rest) { return rest.length; }
            f(1, 2)
        ', 0);
    }

    public function testRestParamIsArray(): void
    {
        $this->assertBothBackends('
            function f(...args) { return args.join("-"); }
            f(1, 2, 3)
        ', '1-2-3');
    }

    // ═══════════════════ Arrow + rest ═══════════════════

    public function testArrowRestOnly(): void
    {
        $this->assertBothBackends('
            var f = (...args) => args.length;
            f(1, 2, 3)
        ', 3);
    }

    public function testArrowRestWithParams(): void
    {
        $this->assertBothBackends('
            var f = (a, ...rest) => a + rest.length;
            f(10, 20, 30)
        ', 12);
    }

    // ═══════════════════ Round-trip (spread + rest) ═══════════════════

    public function testSpreadRestRoundTrip(): void
    {
        $this->assertBothBackends('
            function g(a, b, c) { return a + b + c; }
            function f(...args) { return g(...args); }
            f(1, 2, 3)
        ', 6);
    }

    public function testRestParamWithCallbackMethods(): void
    {
        $this->assertBothBackends('
            function sum(...nums) {
                return nums.reduce((acc, n) => acc + n, 0);
            }
            sum(1, 2, 3, 4, 5)
        ', 15);
    }

    // ═══════════════════ Function declaration + rest ═══════════════════

    public function testFunctionDeclarationRest(): void
    {
        $this->assertBothBackends('
            function test(...items) {
                return items.length;
            }
            test("a", "b", "c")
        ', 3);
    }
}
