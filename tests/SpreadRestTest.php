<?php

declare(strict_types=1);

namespace ScriptLite\Tests;

use ScriptLite\Engine;
use PHPUnit\Framework\TestCase;

final class SpreadRestTest extends TestCase
{
    private Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new Engine();
    }

    // ═══════════════════ Array spread ═══════════════════

    public function testArraySpreadCopy(): void
    {
        $code = '
            var a = [1, 2, 3];
            var b = [...a];
            b.length
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testArraySpreadCopyValues(): void
    {
        $code = '
            var a = [1, 2, 3];
            var b = [...a];
            b[0] + b[1] + b[2]
        ';
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testArraySpreadMerge(): void
    {
        $code = '
            var a = [2, 3];
            var b = [1, ...a, 4];
            b.length
        ';
        self::assertSame(4, $this->engine->eval($code));
    }

    public function testArraySpreadMergeValues(): void
    {
        $code = '
            var a = [2, 3];
            var b = [1, ...a, 4];
            b[0] + b[1] + b[2] + b[3]
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testArraySpreadMultiple(): void
    {
        $code = '
            var a = [1, 2];
            var b = [3, 4];
            var c = [...a, ...b];
            c.length
        ';
        self::assertSame(4, $this->engine->eval($code));
    }

    public function testArraySpreadMultipleValues(): void
    {
        $code = '
            var a = [1, 2];
            var b = [3, 4];
            var c = [...a, ...b];
            c[0] + c[1] + c[2] + c[3]
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testArraySpreadString(): void
    {
        $code = '
            var a = [..."hi"];
            a.length
        ';
        self::assertSame(2, $this->engine->eval($code));
    }

    public function testArraySpreadStringValues(): void
    {
        $code = '
            var a = [..."hi"];
            a[0] + a[1]
        ';
        self::assertSame('hi', $this->engine->eval($code));
    }

    public function testArraySpreadEmpty(): void
    {
        $code = '
            var a = [];
            var b = [1, ...a, 2];
            b.length
        ';
        self::assertSame(2, $this->engine->eval($code));
    }

    // ═══════════════════ Call spread ═══════════════════

    public function testCallSpreadBasic(): void
    {
        $code = '
            function add(a, b, c) { return a + b + c; }
            var args = [1, 2, 3];
            add(...args)
        ';
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testCallSpreadMixed(): void
    {
        $code = '
            function add(a, b, c, d) { return a + b + c + d; }
            var rest = [3, 4];
            add(1, 2, ...rest)
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    public function testCallSpreadMultiple(): void
    {
        $code = '
            function sum(a, b, c, d) { return a + b + c + d; }
            var x = [1, 2];
            var y = [3, 4];
            sum(...x, ...y)
        ';
        self::assertSame(10, $this->engine->eval($code));
    }

    // ═══════════════════ new spread ═══════════════════

    public function testNewSpread(): void
    {
        $code = '
            function Point(x, y) {
                this.x = x;
                this.y = y;
            }
            var args = [10, 20];
            var p = new Point(...args);
            p.x + p.y
        ';
        self::assertSame(30, $this->engine->eval($code));
    }

    // ═══════════════════ Rest parameters ═══════════════════

    public function testRestParamCollectsAll(): void
    {
        $code = '
            function f(...args) { return args.length; }
            f(1, 2, 3)
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testRestParamValues(): void
    {
        $code = '
            function f(...args) { return args[0] + args[1] + args[2]; }
            f(10, 20, 30)
        ';
        self::assertSame(60, $this->engine->eval($code));
    }

    public function testRestParamWithRegular(): void
    {
        $code = '
            function f(a, b, ...rest) { return rest.length; }
            f(1, 2, 3, 4, 5)
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testRestParamWithRegularValues(): void
    {
        $code = '
            function f(a, b, ...rest) { return a + rest[0] + rest[1]; }
            f(1, 2, 3, 4)
        ';
        self::assertSame(8, $this->engine->eval($code));
    }

    public function testRestParamEmpty(): void
    {
        $code = '
            function f(a, b, ...rest) { return rest.length; }
            f(1, 2)
        ';
        self::assertSame(0, $this->engine->eval($code));
    }

    public function testRestParamIsArray(): void
    {
        $code = '
            function f(...args) { return args.join("-"); }
            f(1, 2, 3)
        ';
        self::assertSame('1-2-3', $this->engine->eval($code));
    }

    // ═══════════════════ Arrow + rest ═══════════════════

    public function testArrowRestOnly(): void
    {
        $code = '
            var f = (...args) => args.length;
            f(1, 2, 3)
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    public function testArrowRestWithParams(): void
    {
        $code = '
            var f = (a, ...rest) => a + rest.length;
            f(10, 20, 30)
        ';
        self::assertSame(12, $this->engine->eval($code));
    }

    // ═══════════════════ Round-trip (spread + rest) ═══════════════════

    public function testSpreadRestRoundTrip(): void
    {
        $code = '
            function g(a, b, c) { return a + b + c; }
            function f(...args) { return g(...args); }
            f(1, 2, 3)
        ';
        self::assertSame(6, $this->engine->eval($code));
    }

    public function testRestParamWithCallbackMethods(): void
    {
        $code = '
            function sum(...nums) {
                return nums.reduce((acc, n) => acc + n, 0);
            }
            sum(1, 2, 3, 4, 5)
        ';
        self::assertSame(15, $this->engine->eval($code));
    }

    // ═══════════════════ Function declaration + rest ═══════════════════

    public function testFunctionDeclarationRest(): void
    {
        $code = '
            function test(...items) {
                return items.length;
            }
            test("a", "b", "c")
        ';
        self::assertSame(3, $this->engine->eval($code));
    }

    // ═══════════════════ Transpiler path ═══════════════════

    public function testTranspilerArraySpread(): void
    {
        $php = $this->engine->transpile('
            var a = [1, 2, 3];
            var b = [...a];
            b.length
        ');
        self::assertSame(3, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerArraySpreadMerge(): void
    {
        $php = $this->engine->transpile('
            var a = [2, 3];
            var b = [1, ...a, 4];
            b.length
        ');
        self::assertSame(4, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerCallSpread(): void
    {
        $php = $this->engine->transpile('
            function add(a, b, c) { return a + b + c; }
            var args = [1, 2, 3];
            add(...args)
        ');
        self::assertSame(6, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerRestParam(): void
    {
        $php = $this->engine->transpile('
            function f(...args) { return args[0] + args[1] + args[2]; }
            f(1, 2, 3)
        ');
        self::assertSame(6, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerRestParamWithRegular(): void
    {
        $php = $this->engine->transpile('
            function f(a, b, ...rest) { return a + rest[0]; }
            f(10, 20, 30, 40)
        ');
        self::assertSame(40, $this->engine->evalTranspiled($php));
    }

    public function testTranspilerArrowRest(): void
    {
        $php = $this->engine->transpile('
            var f = (...args) => args[0] + args[1];
            f(10, 20)
        ');
        self::assertSame(30, $this->engine->evalTranspiled($php));
    }
}
